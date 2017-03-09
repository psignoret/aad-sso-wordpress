<?php

if ( ! class_exists( 'AADSSO_Session' ) ) {
	require_once AADSSO_PLUGIN_DIR . '/includes/sessions/AADSSO_Session.php';
}

/**
 * Implementation of AADSSO Session, using native PHP Sessions.
 */
class AADSSO_PHP_Session extends AADSSO_Session {

	/**
	 * Start a multi-server-safe session.
	 *
	 * @return void
	 */
	public function start() {
		if ( ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE !== session_status() ) || ! session_id() ) {
			session_start();
		}
	}

	/**
	 * Close a multi-server-safe session.
	 *
	 * @return void
	 */
	public function destroy() {
		session_destroy();
	}

	/**
	 * Read multi-server-safe session data.
	 *
	 * @param string $key Key.
	 *
	 * @return mixed Value. Null if key is not found.
	 */
	public function get( $key ) {
		if ( isset( $_SESSION[ 'aadsso_' . $key ] ) ) {
			return $_SESSION[ 'aadsso_' . $key ];
		}

		return null;
	}

	/**
	 * Write multi-server-safe session data.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	public function set( $key, $value ) {
		$_SESSION[ 'aadsso_' . $key ] = $value;
	}

	/**
	 * Delete a key.
	 *
	 * @param $key Key to delete.
	 *
	 * @return void
	 */
	public function delete( $key ) {
		unset( $_SESSION[ 'aadsso_' . $key ] );
	}
}