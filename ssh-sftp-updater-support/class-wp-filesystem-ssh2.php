<?php
/**
 * WordPress SSH2 Filesystem.
 *
 * @package WordPress
 * @subpackage Filesystem
 */

use phpseclib3\Net\SFTP as Net_SFTP;
use phpseclib3\Crypt\PublicKeyLoader as Crypt_PublicKeyLoader;

require_once SSH_SFTP_UPDATER_SUPPORT_MAIN_PATH.'/vendor/autoload.php';

/**
 * WordPress Filesystem Class for implementing SSH2.
 *
 * @since 2.7
 * @package WordPress
 * @subpackage Filesystem
 * @uses WP_Filesystem_Base Extends class
 */

class WP_Filesystem_SSH2 extends WP_Filesystem_Base {

	public $link = false;
	public $sftp_link = false;
	public $keys = false;
	public $password = false;
	public $errors = array();
	public $options = array();
	
	private $assymetric_key;

	/**
	 * Class constructor
	 */
	public function __construct($opt='') {
		$this->method = 'ssh2';
		$this->errors = new WP_Error();

		if ( !function_exists('stream_get_contents') ) {
			//translators: PHP function name
			$this->errors->add('ssh2_php_requirement', sprintf(__('The PHP function %s is required, but not present in this PHP install; you should contact your webserver administrator.', 'ssh-sftp-updater-support'), '<code>stream_get_contents()</code>'));
			return false;
		}

		// Set defaults:
		
		$this->options['port'] = empty($opt['port']) ? 22 : $opt['port'];
			
		if ( empty($opt['hostname']) )
			$this->errors->add('empty_hostname', __('SSH2 hostname is required')); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- String is part of WordPress core
		else
			$this->options['hostname'] = $opt['hostname'];

		if ( ! empty($opt['base']) )
			$this->wp_base = $opt['base'];

		if ( !empty ($opt['private_key']) ) {
			$this->options['private_key'] = $opt['private_key'];

			$this->keys = true;
		} elseif ( empty ($opt['username']) ) {
			$this->errors->add('empty_username', __('SSH2 username is required')); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- String is part of WordPress core
		}

		if ( !empty($opt['username']) )
			$this->options['username'] = $opt['username'];

		if ( empty ($opt['password']) ) {
			if ( !$this->keys )	//password can be blank if we are using keys
				$this->errors->add('empty_password', __('SSH2 password is required')); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- String is part of WordPress core
		} else {
			$this->options['password'] = $opt['password'];
			$this->password = true;
		}
	}

	/**
	  * Initiate SFTP connection, and set self::$link
	  * 
	  * @uses self::$link
	  * @uses self::$options
	  * @uses self::$errors
	  * @uses self::$password
	  *
	  * @return Boolean
	  */
	public function connect() {

		if (empty($this->options['hostname']) || empty($this->options['username'])) {
			$this->errors->add('auth', sprintf(__('No connection credentials available', 'ssh-sftp-updater-support'), $this->options));
			return false;
		}
	
		$this->link = new Net_SFTP($this->options['hostname'], $this->options['port']);

		if ( !$this->keys ) {
			if ( ! $this->link->login($this->options['username'], $this->options['password']) ) {
				if ( $this->handle_connect_error() )
					return false;
				// translators: username
				$this->errors->add('auth', sprintf(__('Username/Password incorrect for %s'), $this->options['username'])); // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- String is part of WordPress core
				return false;
			}
		} else {
			// phpseclib2 code
			// $rsa = new Crypt_RSA();
			// if ( $this->password ) $rsa->setPassword($this->options['password']);
			// $rsa->loadKey($this->options['private_key']);
			
			// phpseclib3 made large changes from phpseclib2 in key handling. Example code without any encryption on the key: https://github.com/phpseclib/phpseclib/issues/1604
			
			$password = false;
			if ( $this->password ) $password = $this->options['password'];
			
			if (empty($this->assymetric_key)) {
				// This can be slow, so we want to do it as few times as possible
				try {
					$this->assymetric_key = Crypt_PublicKeyLoader::load($this->options['private_key'], $password);
				} catch (Exception $e) {
					$this->errors->add('auth', sprintf(__('Private key invalid or incorrect for %s', 'ssh-sftp-updater-support'), $this->options['username']).': '.$e->getMessage());
					return false;
				}
			}

			if ( ! $this->link->login($this->options['username'], $this->assymetric_key ) ) {
				if ( $this->handle_connect_error() ) return false;
				// translators: username
				$this->errors->add('auth', sprintf(__('Private key incorrect for %s', 'ssh-sftp-updater-support'), $this->options['username']));
				$this->errors->add('auth', __('The key needs to be the private key, and one supported by phpseclib3 (EC, RSA, DSA, X509) and already known to work with your SFTP server.', 'ssh-sftp-updater-support'));
				return false;
			}
		}

		return true;
	}

	public function handle_connect_error() {
		if ( ! $this->link->isConnected() ) {
			// translators: hostname, port number
			$this->errors->add('connect', sprintf(__('Failed to connect to SSH2 Server %1$s:%2$s', 'ssh-sftp-updater-support'), $this->options['hostname'], $this->options['port']));
			$this->errors->add('connect2', __('If SELinux is installed on your webserver then check to make sure that <code>httpd_can_network_connect</code> is set to 1', 'ssh-sftp-updater-support'));
			return true;
		}

		return false;
	}

	/**
	 * Execute a command over the SFTP link
	 *
	 * @param String  $command
	 * @param Boolean $returnbool
	 *
	 * @return Mixed
	 */
	public function run_command($command, $returnbool = false) {

		if ( ! $this->link )
			return false;

		$data = $this->link->exec($command);

		if ( $returnbool )
			return ( $data === false ) ? false : '' != trim($data);
		else
			return $data;
	}

	/**
	 * Return the contents of a file as a string
	 *
	 * @param String  $file
	 * @param String  $type
	 * @param Integer $resumepos
	 *
	 * @return String
	 */
	public function get_contents($file, $type = '', $resumepos = 0 ) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		return $this->link->get($file);
	}

	public function get_contents_array($file) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		$lines = preg_split('#(\r\n|\r|\n)#', $this->link->get($file), -1, PREG_SPLIT_DELIM_CAPTURE);
		$newLines = array();
		for ($i = 0; $i < count($lines); $i+= 2)
			$newLines[] = $lines[$i] . $lines[$i + 1];
		return $newLines;
	}

	public function put_contents($file, $contents, $mode = false ) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		$ret = $this->link->put($file, $contents);

		$this->chmod($file, $mode);

		return false !== $ret;
	}

	/**
	 * Returns the current directory (with a trailing slash) or false upon failure
	 *
	 * @return String|Boolean
	 */
	public function cwd() {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		$cwd = $this->run_command('pwd');
		return $cwd ? trailingslashit($cwd) : $cwd;
	}

	public function chdir($dir) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		$this->list->chdir($dir);
		return $this->run_command('cd ' . $dir, true);
	}

	public function chgrp($file, $group, $recursive = false ) {
		if ( ! $this->exists($file) )
			return false;
		if ( ! $recursive || ! $this->is_dir($file) )
			return $this->run_command(sprintf('chgrp %o %s', $mode, escapeshellarg($file)), true);
		return $this->run_command(sprintf('chgrp -R %o %s', $mode, escapeshellarg($file)), true);
	}

	public function chmod($file, $mode = false, $recursive = false) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		return $mode === false ? false : $this->link->chmod($mode, $file, $recursive);
	}

	public function chown($file, $owner, $recursive = false ) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		if ( ! $this->exists($file) )
			return false;
		if ( ! $recursive || ! $this->is_dir($file) )
			return $this->run_command(sprintf('chown %o %s', $mode, escapeshellarg($file)), true);
		return $this->run_command(sprintf('chown -R %o %s', $mode, escapeshellarg($file)), true);
	}

	public function owner($file, $owneruid = false) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		if ($owneruid === false) {
			$result = $this->link->stat($file);
			$owneruid = $result['uid'];
		}

		if ( ! $owneruid )
			return false;
		if ( ! function_exists('posix_getpwuid') )
			return $owneruid;
		$ownerarray = posix_getpwuid($owneruid);
		return $ownerarray['name'];
	}

	public function getchmod($file) {
		$result = $this->link->stat($file);
		return substr(decoct($result['mode']), -3, 3);
	}

	public function group($file, $gid = false) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		if ($gid === false) {
			$result = $this->link->stat($file);
			$gid = $result['gid'];
		}

		if ( ! $gid )
			return false;
		if ( ! function_exists('posix_getgrgid') )
			return $gid;
		$grouparray = posix_getgrgid($gid);
		return $grouparray['name'];
	}

	public function copy($source, $destination, $overwrite = false, $mode = false) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		if ( ! $overwrite && $this->exists($destination) )
			return false;
		$content = $this->get_contents($source);
		if ( false === $content)
			return false;
		return $this->put_contents($destination, $content, $mode);
	}

	public function move($source, $destination, $overwrite = false) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		return $this->link->rename($source, $destination);
	}

	public function delete($file, $recursive = false, $type = false) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		if ( 'f' == $type || $this->is_file($file) )
			return $this->link->delete($file);
		if ( ! $recursive )
			return $this->link->rmdir($file);
		return $this->link->delete($file, $recursive);
	}

	public function exists($file) {
		return $this->link->stat($file) !== false;
	}

	public function is_file($file) {
		$result = $this->link->stat($file);
		return is_array($result) && $result['type'] == NET_SFTP_TYPE_REGULAR;
	}

	public function is_dir($path) {
		$result = $this->link->stat($path);
		return is_array($result) && $result['type'] == NET_SFTP_TYPE_DIRECTORY;
	}

	public function is_readable($file) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		return true;

		return is_readable('ssh2.sftp://' . $this->sftp_link . '/' . $file);
	}

	public function is_writable($file) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		return true;
	}

	public function atime($file) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		$result = $this->link->stat($file);
		return $result['atime'];
	}

	public function mtime($file) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		$result = $this->link->stat($file);
		return $result['mtime'];
	}

	public function size($file) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		$result = $this->link->stat($file);
		return $result['size'];
	}

	public function touch($file, $time = 0, $atime = 0) {
		// Not implemented.
	}

	public function mkdir($path, $chmod = false, $chown = false, $chgrp = false) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		$path = untrailingslashit($path);
		if ( ! $chmod )
			$chmod = FS_CHMOD_DIR;
		if ( ! $this->link->mkdir($path) && $this->link->chmod($chmod, $path) )
			return false;
		if ( $chown )
			$this->chown($path, $chown);
		if ( $chgrp )
			$this->chgrp($path, $chgrp);
		return true;
	}

	public function rmdir($path, $recursive = false) {
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		return $this->delete($path, $recursive);
	}

	public function dirlist($path, $include_hidden = true, $recursive = false) {
		
		if (!is_a($this->link, 'phpseclib3\Net\SFTP')) return false;
		
		if ( $this->is_file($path) ) {
			$limit_file = basename($path);
			$path = dirname($path);
		} else {
			$limit_file = false;
		}

		if ( ! $this->is_dir($path) ) return false;

		$ret = array();
		$entries = $this->link->rawlist($path);

		if ( $entries === false ) return false;

		foreach ($entries as $name => $entry) {
			
			/**
			* e.g.
			* 
			* Array
			* (
			*    [size] => 75006
			*    [uid] => 1010
			*    [gid] => 1010
			*    [mode] => 33188 # phpseclib v1 returned 'permissions' instead, but now we must do the conversion (below); the meaning of this value is explained at: https://phpseclib.com/docs/sftp#permissions
			*    [type] => 1
			*    [atime] => 1734797701
			*    [mtime] => 1734797701
			*    [filename] => file.php
			* )
			*/
			
			$struc = array();
			$struc['name'] = $name;

			if ( '.' == $struc['name'] || '..' == $struc['name'] )
				continue; //Do not care about these folders.

			if ( ! $include_hidden && '.' == $struc['name'][0] )
				continue;

			if ( $limit_file && $struc['name'] != $limit_file )
				continue;

			$struc['perms'] 	= $this->convert_permissions_to_unix($entry['mode']);
			$struc['permsn']	= $this->getnumchmodfromh($struc['perms']); // This method is in the parent class, WP_Filesystem_Base; its output is things like "0755" or "0644"
			$struc['number'] 	= false;
			$struc['owner']    	= $this->owner($path.'/'.$name, $entry['uid']);
			$struc['group']    	= $this->group($path.'/'.$name, $entry['gid']);
			$struc['size']    	= $entry['size'];//$this->size($path.'/'.$entry);
			$struc['lastmodunix']= $entry['mtime'];//$this->mtime($path.'/'.$entry);
			$struc['lastmod']   = gmdate('M j',$struc['lastmodunix']); 
			$struc['time']    	= gmdate('h:i:s',$struc['lastmodunix']);
			$struc['type']		= $entry['type'] == NET_SFTP_TYPE_DIRECTORY ? 'd' : 'f';

			if ( 'd' == $struc['type'] ) {
				if ( $recursive )
					$struc['files'] = $this->dirlist($path . '/' . $struc['name'], $include_hidden, $recursive);
				else
					$struc['files'] = array();
			}

			$ret[ $struc['name'] ] = $struc;
		}

		return $ret;
	}
	
	/**
	 * Converts a permissions integer as defined at https://phpseclib.com/docs/sftp#permissions into a textual UNIX representation (e.g. "-rw-r--r--")
	 *
	 * From WP_Filesystem_Base::gethchmod()
	 *
	 * @param Integer $perms
	 *
	 * @return String
	 */
	private function convert_permissions_to_unix($perms) {
		
		if ( ( $perms & 0xC000 ) === 0xC000 ) { // Socket.
			$info = 's';
		} elseif ( ( $perms & 0xA000 ) === 0xA000 ) { // Symbolic Link.
			$info = 'l';
		} elseif ( ( $perms & 0x8000 ) === 0x8000 ) { // Regular.
			$info = '-';
		} elseif ( ( $perms & 0x6000 ) === 0x6000 ) { // Block special.
			$info = 'b';
		} elseif ( ( $perms & 0x4000 ) === 0x4000 ) { // Directory.
			$info = 'd';
		} elseif ( ( $perms & 0x2000 ) === 0x2000 ) { // Character special.
			$info = 'c';
		} elseif ( ( $perms & 0x1000 ) === 0x1000 ) { // FIFO pipe.
			$info = 'p';
		} else { // Unknown.
			$info = 'u';
		}
		
		// Owner.
		$info .= ( ( $perms & 0x0100 ) ? 'r' : '-' );
		$info .= ( ( $perms & 0x0080 ) ? 'w' : '-' );
		$info .= ( ( $perms & 0x0040 ) ?
		( ( $perms & 0x0800 ) ? 's' : 'x' ) :
		( ( $perms & 0x0800 ) ? 'S' : '-' ) );
		
		// Group.
		$info .= ( ( $perms & 0x0020 ) ? 'r' : '-' );
		$info .= ( ( $perms & 0x0010 ) ? 'w' : '-' );
		$info .= ( ( $perms & 0x0008 ) ?
		( ( $perms & 0x0400 ) ? 's' : 'x' ) :
		( ( $perms & 0x0400 ) ? 'S' : '-' ) );
		
		// World.
		$info .= ( ( $perms & 0x0004 ) ? 'r' : '-' );
		$info .= ( ( $perms & 0x0002 ) ? 'w' : '-' );
		$info .= ( ( $perms & 0x0001 ) ?
		( ( $perms & 0x0200 ) ? 't' : 'x' ) :
		( ( $perms & 0x0200 ) ? 'T' : '-' ) );
		
		return $info;
	}
	
	/**
	 * Locates a folder on the remote filesystem. Added from includes/class-wp-filesystem-base.php in 0.8.2 to make the FTP_constants work
	 *
	 * Assumes that on Windows systems, Stripping off the Drive
	 * letter is OK Sanitizes \\ to / in Windows filepaths.
	 *
	 * @since 2.7.0
	 *
	 * @param string $folder the folder to locate.
	 * @return string|false The location of the remote path, false on failure.
	 */
	public function find_folder( $folder ) {
		if ( isset( $this->cache[ $folder ] ) ) {
			return $this->cache[ $folder ];
		}

		// The true here is because we always want to use them if inside this class - a minimal change from the core method, making future merging of upstream changes easier
		if ( true || stripos( $this->method, 'ftp' ) !== false ) {
			$constant_overrides = array(
				'FTP_BASE'        => ABSPATH,
				'FTP_CONTENT_DIR' => WP_CONTENT_DIR,
				'FTP_PLUGIN_DIR'  => WP_PLUGIN_DIR,
				'FTP_LANG_DIR'    => WP_LANG_DIR,
			);

			// Direct matches ( folder = CONSTANT/ )
			foreach ( $constant_overrides as $constant => $dir ) {
				if ( ! defined( $constant ) ) {
					continue;
				}
				if ( $folder === $dir ) {
					return trailingslashit( constant( $constant ) );
				}
			}

			// Prefix Matches ( folder = CONSTANT/subdir )
			foreach ( $constant_overrides as $constant => $dir ) {
				if ( ! defined( $constant ) ) {
					continue;
				}
				if ( 0 === stripos( $folder, $dir ) ) { // $folder starts with $dir
					$potential_folder = preg_replace( '#^' . preg_quote( $dir, '#' ) . '/#i', trailingslashit( constant( $constant ) ), $folder );
					$potential_folder = trailingslashit( $potential_folder );

					if ( $this->is_dir( $potential_folder ) ) {
						$this->cache[ $folder ] = $potential_folder;
						return $potential_folder;
					}
				}
			}
		} elseif ( 'direct' == $this->method ) {
			$folder = str_replace( '\\', '/', $folder ); // Windows path sanitisation
			return trailingslashit( $folder );
		}

		$folder = preg_replace( '|^([a-z]{1}):|i', '', $folder ); // Strip out windows drive letter if it's there.
		$folder = str_replace( '\\', '/', $folder ); // Windows path sanitisation

		if ( isset( $this->cache[ $folder ] ) ) {
			return $this->cache[ $folder ];
		}

		if ( $this->exists( $folder ) ) { // Folder exists at that absolute path.
			$folder                 = trailingslashit( $folder );
			$this->cache[ $folder ] = $folder;
			return $folder;
		}
		if ( $return = $this->search_for_folder( $folder ) ) {
			$this->cache[ $folder ] = $return;
		}
		return $return;
	}
}
