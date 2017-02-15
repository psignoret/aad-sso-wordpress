<?php

/**
 * Abstract AADSSO Session class.
 *
 * You should implement this class to use your own
 * key/value store like memcache, redis etc for session storage.
 */
abstract class AADSSO_Session {

	/**
	 * Start a multi-server-safe session.
	 *
	 * @return void
	 */
	abstract public function start();

	/**
	 * Close a multi-server-safe session.
	 *
	 * @return void
	 */
	abstract public function destroy();

	/**
	 * Read multi-server-safe session data.
	 *
	 * @param string $key Key.
	 *
	 * @return mixed Value. Null if key is not found.
	 */
	abstract public function read( $key );

	/**
	 * Write multi-server-safe session data.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 *
	 * @return void
	 */
	abstract public function write( $key, $value );

	/**
	 * Delete a key.
	 *
	 * @param $key Key to delete.
	 *
	 * @return void
	 */
	abstract public function delete( $key );
}