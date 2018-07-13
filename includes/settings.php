<?php
/**
 * Settings handlers for Platform plugins.
 */

namespace HM\Platform\Settings;

// WP SEO settings.
use const HM\Platform\ROOT_DIR;

add_action( 'hm.platform.seo.settings', function ( $settings ) {
	// Hide the admin settings page.
	if ( ! empty( $settings['hide-settings-page'] ) ) {
		add_action( 'admin_menu', function () {
			remove_submenu_page( 'options-general.php', 'wp-seo' );
		}, 20 );
	}
} );

add_action( 'hm.platform.seo.settings.early', function ( $settings ) {
	// Fake premium.
	if ( isset( $settings['fake-premium'] ) && $settings['fake-premium'] ) {
		require_once ROOT_DIR . '/lib/wordpress-seo/bootstrap.php';

		defined( 'WPSEO_PREMIUM_FILE' ) or define( 'WPSEO_PREMIUM_FILE', true );
		defined( 'WPSEO_PREMIUM_PLUGIN_FILE' ) or define( 'WPSEO_PREMIUM_PLUGIN_FILE', true );
	}
} );

// GTM settings.
add_action( 'hm.platform.google-tag-manager.settings', function ( $settings = [] ) {
	$settings = array_merge( [
		'network-container-id' => null,
		'container-id'         => null,
	], $settings );

	// Hide the admin settings page.
	if ( $settings['network-container-id'] ) {
		add_filter( 'pre_option_hm_gtm_network_id', function () use ( $settings ) {
			return sanitize_text_field( $settings['network-container-id'] );
		} );
	}

	if ( $settings['container-id'] ) {
		// Per site containers.
		if ( is_array( $settings['container-id'] ) ) {
			foreach ( $settings['container-id'] as $url => $id ) {
				if ( strpos( get_home_url(), $url ) === false ) {
					continue;
				}

				add_filter( 'pre_option_hm_gtm_id', function () use ( $id ) {
					return sanitize_text_field( $id );
				} );
			}
		} elseif ( is_string( $settings['container-id'] ) ) {
			add_filter( 'pre_option_hm_gtm_id', function () use ( $settings ) {
				return sanitize_text_field( $settings['container-id'] );
			} );
		}
	}
} );

// MLP Settings.
add_action( 'hm.platform.multilingualpress.settings', function () {
	remove_action( 'inpsyde_mlp_loaded', 'mlp_register_become_inpsyder_admin_notice' );
} );

// ElasticPress.
add_action( 'hm.platform.elasticpress.settings.early', function ( $settings = [] ) {

	// Disable elasticpress.io warnings.
	add_filter( 'ep_feature_requirements_status', function ( $status, $feature ) {
		$status->message = array_filter( (array) $status->message, function ( $message ) {
			return ! preg_match( '#elasticpress\.io#i', $message );
		} );

		// Prevent documents being auto enabled.
		if ( 'documents' === $feature ) {
			$plugins = ep_get_elasticsearch_plugins();

			$status->code = 2;

			// Ingest attachment plugin is required for this feature.
			if ( empty( $plugins ) || empty( $plugins['ingest-attachment'] ) ) {
				$status->message[] = __( 'The <a href="https://www.elastic.co/guide/en/elasticsearch/plugins/master/ingest-attachment.html">Ingest Attachment plugin</a> for Elasticsearch is not installed.', 'hm-platform' );
			}
		}

		return $status;
	}, 10, 2 );

	// Put EP in network mode.
	if ( isset( $settings['network'] ) && $settings['network'] ) {
		add_action( 'muplugins_loaded', function () {
			if ( is_multisite() ) {
				defined( 'EP_IS_NETWORK' ) or define( 'EP_IS_NETWORK', true );
			}
		}, 9 );
	}

	// Preconfigure and handle autosuggest endpoint.
	if ( isset( $settings['autosuggest'] ) && $settings['autosuggest'] ) {

		// Filter EP settings.
		add_action( 'ep_feature_setup', function ( $slug ) {
			if ( 'autosuggest' !== $slug ) {
				return;
			}

			$option_filter = function ( $settings ) use ( $slug ) {
				if ( ! isset( $settings[ $slug ] ) ) {
					return $settings;
				}

				$settings[ $slug ]['endpoint_url'] = get_home_url( null, '/autosuggest/' );

				return $settings;
			};

			if ( defined( 'EP_IS_NETWORK' ) && EP_IS_NETWORK ) {
				add_filter( 'site_option_ep_feature_settings', $option_filter );
			} else {
				add_filter( 'option_ep_feature_settings', $option_filter );
			}
		}, 10 );

		// Handle request forwarding to ES.
		add_action( 'template_redirect', function () {
			if ( '/autosuggest' !== $_SERVER['REQUEST_URI'] ) {
				return;
			}

			// if ( parse_url( $_SERVER['HTTP_ORIGIN'], PHP_URL_HOST ) !== parse_url( get_home_url(), PHP_URL_HOST ) ) {
			// 	wp_send_json( [] );
			// }

			// Validate data.
			$json = json_decode( file_get_contents( 'php://input' ), true );
			if ( ! $json ) {
				wp_send_json( [] );
			}

			// Force post filter value.
			$json['post_filter'] = [
				"bool" => [
					"must" => [
						[
							"term" => [
								"post_status" => "publish",
							],
						],
						[
							"terms" => [
								"post_type.raw" => array_values( ep_get_searchable_post_types() ),
							],
						],
					],
				],
			];

			// Pass to EP.
			$response = ep_remote_request( ep_get_index_name() . '/post/_search', [
				'body'   => json_encode( $json ),
				'method' => 'POST',
			] );

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			// Return JSON response.
			wp_send_json( $data );
		} );
	}
} );

// Tachyon.
add_action( 'hm.platform.tachyon.settings', function ( $settings = [] ) {
	// Enable smart cropping.
	if ( $settings['smart-cropping'] ) {
		add_filter( 'tachyon_pre_args', function ( $args ) {
			if ( isset( $args['resize'] ) ) {
				$args['crop_strategy'] = 'smart';
			}
			return $args;
		} );
	}
} );

// Rekognition.
add_action( 'hm.platform.rekognition.settings', function ( $settings = [] ) {
	// Toggle label detection.
	if ( $settings['labels'] ) {
		add_filter( 'hm.aws.rekognition.labels', $settings['labels'] ? '__return_true' : '__return_false' );
	}
	// Toggle moderation label detection.
	if ( $settings['moderation'] ) {
		add_filter( 'hm.aws.rekognition.labels', $settings['labels'] ? '__return_true' : '__return_false' );
	}
	// Toggle face detection.
	if ( $settings['faces'] ) {
		add_filter( 'hm.aws.rekognition.labels', $settings['labels'] ? '__return_true' : '__return_false' );
	}
	// Toggle celebrity detection.
	if ( $settings['celebrities'] ) {
		add_filter( 'hm.aws.rekognition.labels', $settings['labels'] ? '__return_true' : '__return_false' );
	}
	// Toggle text detection.
	if ( $settings['text'] ) {
		add_filter( 'hm.aws.rekognition.labels', $settings['labels'] ? '__return_true' : '__return_false' );
	}
} );