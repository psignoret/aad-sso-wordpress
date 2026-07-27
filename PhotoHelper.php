<?php

/**
 * Synchronizes the signed-in user's Microsoft Graph photo to WordPress and Ultimate Member.
 */
class AADSSO_PhotoHelper {

	const ATTACHMENT_META_KEY = 'um_graph_avatar_id';
	const PROFILE_PHOTO_META_KEY = 'profile_photo';
	const SYNCED_AT_META_KEY = 'aadsso_graph_photo_synced_at';
	const MAX_PHOTO_BYTES = 10485760;

	public static function user_has_profile_photo( $user_id ) {
		$attachment_id = (int) get_user_meta( $user_id, self::ATTACHMENT_META_KEY, true );
		if ( $attachment_id > 0 ) {
			$attached_file = get_attached_file( $attachment_id );
			if ( $attached_file && file_exists( $attached_file ) ) {
				return true;
			}
		}

		$file_name = basename(
			(string) get_user_meta( $user_id, self::PROFILE_PHOTO_META_KEY, true )
		);
		if ( '' !== $file_name ) {
			$upload = wp_upload_dir();
			if ( empty( $upload['error'] ) ) {
				$file_path = trailingslashit( $upload['basedir'] )
					. 'ultimatemember/' . (int) $user_id . '/' . $file_name;
				if ( file_exists( $file_path ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public static function sync_current_user_photo( $user_id, $force = false ) {
		if ( ! $force && self::user_has_profile_photo( $user_id ) ) {
			return new WP_Error(
				'photo_exists',
				__( 'A local profile photo is already set.', 'aad-sso-wordpress' )
			);
		}

		$photo = AADSSO_GraphHelper::get_current_user_photo();
		if ( is_wp_error( $photo ) ) {
			return $photo;
		}

		$saved_photo = self::save_photo( $user_id, $photo['bytes'], $photo['content_type'] );
		if ( is_wp_error( $saved_photo ) ) {
			return $saved_photo;
		}

		update_user_meta( $user_id, self::PROFILE_PHOTO_META_KEY, $saved_photo['filename'] );
		update_user_meta( $user_id, self::ATTACHMENT_META_KEY, $saved_photo['attachment_id'] );
		update_user_meta( $user_id, self::SYNCED_AT_META_KEY, current_time( 'mysql' ) );
		// Retain the legacy timestamp while the standalone photo plugin is being replaced.
		update_user_meta( $user_id, 'um_graph_photo_synced_at', current_time( 'mysql' ) );

		do_action( 'um_after_upload_complete', $user_id );
		do_action( 'aadsso_profile_photo_synced', $user_id, $saved_photo['attachment_id'] );
		return true;
	}

	private static function save_photo( $user_id, $bytes, $content_type ) {
		if ( '' === $bytes ) {
			return new WP_Error( 'empty_photo', __( 'Microsoft Graph returned an empty photo.', 'aad-sso-wordpress' ) );
		}
		if ( strlen( $bytes ) > self::MAX_PHOTO_BYTES ) {
			return new WP_Error( 'photo_too_large', __( 'The Microsoft Graph photo exceeds 10 MB.', 'aad-sso-wordpress' ) );
		}

		$image_info = @getimagesizefromstring( $bytes );
		if ( false === $image_info || empty( $image_info['mime'] ) ) {
			return new WP_Error( 'invalid_photo', __( 'Microsoft Graph did not return a valid image.', 'aad-sso-wordpress' ) );
		}
		$allowed_types = array(
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
		);
		if ( ! isset( $allowed_types[ $image_info['mime'] ] ) ) {
			return new WP_Error( 'unsupported_photo', __( 'The Microsoft Graph photo format is not supported.', 'aad-sso-wordpress' ) );
		}

		$extension = $allowed_types[ $image_info['mime'] ];
		$file_name = 'profile_photo.' . $extension;
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'photo_upload_directory', $upload['error'] );
		}
		$user_directory = trailingslashit( $upload['basedir'] )
			. 'ultimatemember/' . (int) $user_id;
		if ( ! wp_mkdir_p( $user_directory ) ) {
			return new WP_Error( 'photo_directory', __( 'The profile photo directory could not be created.', 'aad-sso-wordpress' ) );
		}

		$file_path = trailingslashit( $user_directory ) . $file_name;
		if ( false === file_put_contents( $file_path, $bytes, LOCK_EX ) ) {
			return new WP_Error( 'photo_write', __( 'The profile photo could not be written.', 'aad-sso-wordpress' ) );
		}

		if ( ! function_exists( 'wp_get_image_editor' )
			|| ! function_exists( 'wp_generate_attachment_metadata' )
		) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$editor = wp_get_image_editor( $file_path );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 190, 190, true );
			$editor->save(
				trailingslashit( $user_directory ) . 'profile_photo-190x190.' . $extension
			);
		}

		$attachment_id = (int) get_user_meta(
			$user_id,
			self::ATTACHMENT_META_KEY,
			true
		);
		$attachment = array(
			'post_mime_type' => $image_info['mime'],
			'post_title' => sanitize_file_name( 'profile_photo_user_' . (int) $user_id ),
			'post_content' => '',
			'post_status' => 'inherit',
		);
		if ( $attachment_id > 0 && get_post( $attachment_id ) ) {
			$attachment['ID'] = $attachment_id;
			$updated = wp_update_post( $attachment, true );
			if ( is_wp_error( $updated ) ) {
				return $updated;
			}
			update_attached_file( $attachment_id, $file_path );
		} else {
			$attachment_id = wp_insert_attachment( $attachment, $file_path, 0, true );
			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}
		}

		$attachment_metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
		if ( ! is_wp_error( $attachment_metadata ) ) {
			wp_update_attachment_metadata( $attachment_id, $attachment_metadata );
		}
		self::remove_old_profile_photo_variants( $user_directory, $extension );

		return array(
			'filename' => $file_name,
			'path' => $file_path,
			'attachment_id' => (int) $attachment_id,
			'content_type' => ! empty( $content_type ) ? $content_type : $image_info['mime'],
		);
	}

	private static function remove_old_profile_photo_variants( $user_directory, $current_extension ) {
		foreach ( array( 'jpg', 'jpeg', 'png', 'gif', 'webp' ) as $extension ) {
			if ( $current_extension === $extension ) {
				continue;
			}
			foreach ( array( 'profile_photo.', 'profile_photo-190x190.' ) as $prefix ) {
				$stale_file = trailingslashit( $user_directory ) . $prefix . $extension;
				if ( file_exists( $stale_file ) ) {
					wp_delete_file( $stale_file );
				}
			}
		}
	}

	private static function resolve_user( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return get_user_by( 'id', (int) $id_or_email );
		}
		if ( is_object( $id_or_email ) ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				$user = get_user_by( 'id', (int) $id_or_email->user_id );
				if ( $user ) {
					return $user;
				}
			}
			if ( ! empty( $id_or_email->comment_author_email ) ) {
				$user = get_user_by( 'email', $id_or_email->comment_author_email );
				if ( $user ) {
					return $user;
				}
			}
		}
		if ( is_string( $id_or_email ) ) {
			return get_user_by( 'email', $id_or_email );
		}
		return false;
	}

	public static function filter_avatar_data( $args, $id_or_email ) {
		$user = self::resolve_user( $id_or_email );
		if ( ! $user ) {
			return $args;
		}
		$attachment_id = (int) get_user_meta( $user->ID, self::ATTACHMENT_META_KEY, true );
		$size = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$url = $attachment_id > 0
			? wp_get_attachment_image_url( $attachment_id, array( $size, $size ) ) : false;
		if ( $url ) {
			$args['url'] = $url;
			$args['found_avatar'] = true;
		}
		return $args;
	}

	public static function filter_avatar_url( $url, $id_or_email, $args ) {
		$avatar_data = self::filter_avatar_data( $args, $id_or_email );
		return ! empty( $avatar_data['url'] ) ? $avatar_data['url'] : $url;
	}

	public static function filter_avatar_html( $avatar, $id_or_email, $size, $default, $alt ) {
		$user = self::resolve_user( $id_or_email );
		if ( ! $user ) {
			return $avatar;
		}
		$attachment_id = (int) get_user_meta( $user->ID, self::ATTACHMENT_META_KEY, true );
		if ( $attachment_id <= 0 ) {
			return $avatar;
		}
		$image = wp_get_attachment_image(
			$attachment_id,
			array( (int) $size, (int) $size ),
			false,
			array(
				'alt' => $alt,
				'class' => 'avatar avatar-' . (int) $size . ' photo aadsso-graph-avatar',
			)
		);
		return $image ? $image : $avatar;
	}
}
