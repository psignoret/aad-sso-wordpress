<?php

/**
 * Abstract AADSSO Session class.
 *
 * You should implement this class to use your own
 * key/value store like memcache, redis etc for session storage.
 */
abstract class AADSSO_Session {

	/**
	 * Start a session.
	 *
	 * @return void
	 */
	abstract public function start();

	/**
	 * Close a session.
	 *
	 * @return void
	 */
	abstract public function destroy();

	/**
	 * Get session data.
	 *
	 * @param string $key Key.
	 *
	 * @return mixed Value. Null if key is not found.
	 */
	abstract public function get( $key );

	/**
	 * Set session data.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	abstract public function set( $key, $value );

	/**
	 * Delete a key.
	 *
	 * @param $key Key to delete.
	 *
	 * @return void
	 */
	abstract public function delete( $key );
}