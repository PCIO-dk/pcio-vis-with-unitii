<?php
/**
 * Unitii GraphQL API client.
 *
 * @package PCIO_VIS_With_Unitii
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logs in to the Unitii GraphQL API and fetches volunteers with their shifts.
 */
class PCIO_VIS_With_Unitii_Client {

	const PAGE_SIZE = 10;
	const MAX_PAGES = 1000;

	private const LOGIN_MUTATION = 'mutation Login($loginData: LoginInput!) { login(loginData: $loginData) { accessToken refreshToken } }';

	private const VOLUNTEERS_QUERY = 'query GetVolunteersAndShifts($range: DateRange!, $skip: Float = 0, $take: Float = 10) {
  getVolunteersAndShifts(range: $range, skip: $skip, take: $take) {
    items {
      volunteer { id firstName lastName email phoneNumber hours deleted userType createdAt }
      shifts {
        id startDateTime endDateTime location
        event { id title startDateTime endDateTime description }
      }
      totalTime
    }
    total
    hasMore
  }
}';

	/** @var string */
	private $url;

	/** @var string */
	private $token = '';

	public function __construct( string $url ) {
		$this->url = $url;
	}

	/**
	 * Obtain an access token.
	 *
	 * @return true|WP_Error
	 */
	public function login( string $email, string $password ) {
		$json = $this->request(
			'login',
			self::LOGIN_MUTATION,
			[
				'loginData' => [
					'email'    => $email,
					'password' => $password,
				],
			]
		);
		if ( is_wp_error( $json ) ) {
			return $json;
		}
		$token = $json['data']['login']['accessToken'] ?? '';
		if ( ! is_string( $token ) || '' === $token ) {
			return new WP_Error(
				'pcio_unitii_no_token',
				__( 'Unitii login failed: no access token in the response. Check the username and password.', 'pcio-vis-with-unitii' )
			);
		}
		$this->token = $token;
		return true;
	}

	/**
	 * Fetch every volunteer-with-shifts item in the date range (all pages).
	 *
	 * @param int $start_ms Range start, Unix milliseconds.
	 * @param int $end_ms   Range end, Unix milliseconds.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function get_volunteers_and_shifts( int $start_ms, int $end_ms ) {
		$items = [];
		$skip  = 0;
		for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
			$json = $this->request(
				'getVolunteersAndShifts',
				self::VOLUNTEERS_QUERY,
				[
					'range' => [
						'start' => $start_ms,
						'end'   => $end_ms,
					],
					'skip'  => $skip,
					'take'  => self::PAGE_SIZE,
				]
			);
			if ( is_wp_error( $json ) ) {
				return $json;
			}
			$data = $json['data']['getVolunteersAndShifts'] ?? null;
			if ( ! is_array( $data ) || ! isset( $data['items'] ) || ! is_array( $data['items'] ) ) {
				return new WP_Error(
					'pcio_unitii_bad_data',
					__( 'Invalid data structure from the Unitii API.', 'pcio-vis-with-unitii' )
				);
			}
			foreach ( $data['items'] as $item ) {
				$items[] = $item;
			}
			$total = (int) ( $data['total'] ?? 0 );
			$skip += self::PAGE_SIZE;
			if ( $skip >= $total || [] === $data['items'] ) {
				return $items;
			}
		}
		return $items;
	}

	/**
	 * POST a GraphQL request and decode the JSON response.
	 *
	 * @param string              $name      Operation name (for error messages).
	 * @param string              $query     GraphQL query.
	 * @param array<string,mixed> $variables Variables.
	 * @return array<string,mixed>|WP_Error
	 */
	private function request( string $name, string $query, array $variables ) {
		$headers = [
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		];
		if ( '' !== $this->token ) {
			$headers['Authorization'] = 'Bearer ' . $this->token;
		}

		$response = wp_remote_post(
			$this->url,
			[
				'timeout' => 30,
				'headers' => $headers,
				'body'    => wp_json_encode(
					[
						'query'     => $query,
						'variables' => $variables,
					]
				),
			]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'pcio_unitii_http',
				sprintf(
					/* translators: 1: GraphQL operation name, 2: HTTP status code, 3: start of the response body */
					__( 'Unitii %1$s returned HTTP %2$d: %3$s', 'pcio-vis-with-unitii' ),
					$name,
					$code,
					substr( wp_strip_all_tags( $body ), 0, 300 )
				)
			);
		}

		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'pcio_unitii_json',
				sprintf(
					/* translators: %s: GraphQL operation name */
					__( 'Could not parse the JSON response from Unitii %s.', 'pcio-vis-with-unitii' ),
					$name
				)
			);
		}

		if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
			$messages = [];
			foreach ( $json['errors'] as $error ) {
				$messages[] = is_array( $error ) ? (string) ( $error['message'] ?? '' ) : (string) $error;
			}
			return new WP_Error(
				'pcio_unitii_graphql',
				sprintf(
					/* translators: 1: GraphQL operation name, 2: error messages */
					__( 'Unitii %1$s error: %2$s', 'pcio-vis-with-unitii' ),
					$name,
					implode( '; ', array_filter( $messages ) )
				)
			);
		}

		return $json;
	}
}
