<?php
/**
 * Object caching using WinCache.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Cache
 */

/**
 * Wrapper for WinCache object caching functions; identical interface
 * to the APC wrapper
 *
 * @ingroup Cache
 */
class WinCacheBagOStuff extends BagOStuff {
	protected function doGet( $key, $flags = 0 ) {
		$blob = wincache_ucache_get( $key );

		return is_string( $blob ) ? unserialize( $blob ) : false;
	}

	protected function getWithToken( $key, &$casToken, $flags = 0 ) {
		$casToken = null;

		$blob = wincache_ucache_get( $key );
		if ( !is_string( $blob ) ) {
			return false;
		}

		$value = unserialize( $blob );
		if ( $value === false ) {
			return false;
		}

		$casToken = $blob; // don't bother hashing this

		return $value;
	}

	protected function cas( $casToken, $key, $value, $exptime = 0, $flags = 0 ) {
		if ( !wincache_lock( $key ) ) { // optimize with FIFO lock
			return false;
		}

		$curCasToken = null; // passed by reference
		$this->getWithToken( $key, $curCasToken, self::READ_LATEST );
		if ( $casToken === $curCasToken ) {
			$success = $this->set( $key, $value, $exptime, $flags );
		} else {
			$this->logger->info(
				__METHOD__ . ' failed due to race condition for {key}.',
				[ 'key' => $key ]
			);

			$success = false; // mismatched or failed
		}

		wincache_unlock( $key );

		return $success;
	}

	public function set( $key, $value, $expire = 0, $flags = 0 ) {
		$result = wincache_ucache_set( $key, serialize( $value ), $expire );

		return ( $result === [] || $result === true );
	}

	public function add( $key, $value, $exptime = 0, $flags = 0 ) {
		$result = wincache_ucache_add( $key, serialize( $value ), $exptime );

		return ( $result === [] || $result === true );
	}

	public function delete( $key, $flags = 0 ) {
		wincache_ucache_delete( $key );

		return true;
	}

	public function merge( $key, callable $callback, $exptime = 0, $attempts = 10, $flags = 0 ) {
		return $this->mergeViaCas( $key, $callback, $exptime, $attempts, $flags );
	}

	/**
	 * Construct a cache key.
	 *
	 * @since 1.27
	 * @param string $keyspace
	 * @param array $args
	 * @return string
	 */
	public function makeKeyInternal( $keyspace, $args ) {
		// WinCache keys have a maximum length of 150 characters. From that,
		// subtract the number of characters we need for the keyspace and for
		// the separator character needed for each argument. To handle some
		// custom prefixes used by thing like WANObjectCache, limit to 125.
		// NOTE: Same as in memcached, except the max key length there is 255.
		$charsLeft = 125 - strlen( $keyspace ) - count( $args );

		$args = array_map(
			function ( $arg ) use ( &$charsLeft ) {
				// 33 = 32 characters for the MD5 + 1 for the '#' prefix.
				if ( $charsLeft > 33 && strlen( $arg ) > $charsLeft ) {
					$arg = '#' . md5( $arg );
				}

				$charsLeft -= strlen( $arg );
				return $arg;
			},
			$args
		);

		if ( $charsLeft < 0 ) {
			return $keyspace . ':BagOStuff-long-key:##' . md5( implode( ':', $args ) );
		}

		return $keyspace . ':' . implode( ':', $args );
	}

	/**
	 * Increase stored value of $key by $value while preserving its original TTL
	 * @param string $key Key to increase
	 * @param int $value Value to add to $key (Default 1)
	 * @return int|bool New value or false on failure
	 */
	public function incr( $key, $value = 1 ) {
		if ( !wincache_lock( $key ) ) { // optimize with FIFO lock
			return false;
		}

		$n = $this->doGet( $key );
		if ( $this->isInteger( $n ) ) {
			$n = max( $n + (int)$value, 0 );
			$oldTTL = wincache_ucache_info( false, $key )["ucache_entries"][1]["ttl_seconds"];
			$this->set( $key, $n, $oldTTL );
		} else {
			$n = false;
		}

		wincache_unlock( $key );

		return $n;
	}
}
