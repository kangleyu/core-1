<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Christopher Schäpers <kondou@ts.unde.re>
 * @author Florin Peter <github@florin-peter.de>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Martin Mattel <martin.mattel@diemattels.at>
 * @author Michael Gapczynski <GapczynskiM@gmail.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Robin McCorkell <robin@mccorkell.me.uk>
 * @author Roeland Jago Douma <rullzer@owncloud.com>
 * @author Sam Tuke <mail@samtuke.com>
 * @author Stephan Peijnik <speijnik@anexia-it.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

/**
 * Class for abstraction of filesystem functions
 * This class won't call any filesystem functions for itself but will pass them to the correct OC_Filestorage object
 * this class should also handle all the file permission related stuff
 *
 * Hooks provided:
 *   read(path)
 *   write(path, &run)
 *   post_write(path)
 *   create(path, &run) (when a file is created, both create and write will be emitted in that order)
 *   post_create(path)
 *   delete(path, &run)
 *   post_delete(path)
 *   rename(oldpath,newpath, &run)
 *   post_rename(oldpath,newpath)
 *   copy(oldpath,newpath, &run) (if the newpath doesn't exists yes, copy, create and write will be emitted in that order)
 *   post_rename(oldpath,newpath)
 *   post_initMountPoints(user, user_dir)
 *
 *   the &run parameter can be set to false to prevent the operation from occurring
 */

namespace OC\Files;

use OC\Cache\CappedMemoryCache;
use OC\Files\Config\MountProviderCollection;
use OC\Files\Mount\MountPoint;
use OC\Files\Storage\StorageFactory;
use OCP\Files\Config\IMountProvider;
use OCP\Files\NotFoundException;
use OCP\IUserManager;

class Filesystem {

	/**
	 * @var Mount\Manager $mounts
	 */
	private static $mounts;

	public static $loaded = false;
	/**
	 * @var \OC\Files\View $defaultInstance
	 */
	static private $defaultInstance;

	static private $usersSetup = [];

	static private $normalizedPathCache = null;

	static private $listeningForProviders = false;

	/**
	 * classname which used for hooks handling
	 * used as signalclass in OC_Hooks::emit()
	 */
	const CLASSNAME = 'OC_Filesystem';

	/**
	 * signalname emitted before file renaming
	 *
	 * @param string $oldpath
	 * @param string $newpath
	 */
	const signal_rename = 'rename';

	/**
	 * signal emitted after file renaming
	 *
	 * @param string $oldpath
	 * @param string $newpath
	 */
	const signal_post_rename = 'post_rename';

	/**
	 * signal emitted before file/dir creation
	 *
	 * @param string $path
	 * @param bool $run changing this flag to false in hook handler will cancel event
	 */
	const signal_create = 'create';

	/**
	 * signal emitted after file/dir creation
	 *
	 * @param string $path
	 * @param bool $run changing this flag to false in hook handler will cancel event
	 */
	const signal_post_create = 'post_create';

	/**
	 * signal emits before file/dir copy
	 *
	 * @param string $oldpath
	 * @param string $newpath
	 * @param bool $run changing this flag to false in hook handler will cancel event
	 */
	const signal_copy = 'copy';

	/**
	 * signal emits after file/dir copy
	 *
	 * @param string $oldpath
	 * @param string $newpath
	 */
	const signal_post_copy = 'post_copy';

	/**
	 * signal emits before file/dir save
	 *
	 * @param string $path
	 * @param bool $run changing this flag to false in hook handler will cancel event
	 */
	const signal_write = 'write';

	/**
	 * signal emits after file/dir save
	 *
	 * @param string $path
	 */
	const signal_post_write = 'post_write';

	/**
	 * signal emitted before file/dir update
	 *
	 * @param string $path
	 * @param bool $run changing this flag to false in hook handler will cancel event
	 */
	const signal_update = 'update';

	/**
	 * signal emitted after file/dir update
	 *
	 * @param string $path
	 * @param bool $run changing this flag to false in hook handler will cancel event
	 */
	const signal_post_update = 'post_update';

	/**
	 * signal emits when reading file/dir
	 *
	 * @param string $path
	 */
	const signal_read = 'read';

	/**
	 * signal emits when removing file/dir
	 *
	 * @param string $path
	 */
	const signal_delete = 'delete';

	/**
	 * parameters definitions for signals
	 */
	const signal_param_path = 'path';
	const signal_param_oldpath = 'oldpath';
	const signal_param_newpath = 'newpath';

	/**
	 * run - changing this flag to false in hook handler will cancel event
	 */
	const signal_param_run = 'run';

	const signal_create_mount = 'create_mount';
	const signal_delete_mount = 'delete_mount';
	const signal_param_mount_type = 'mounttype';
	const signal_param_users = 'users';

	/**
	 * @var \OC\Files\Storage\StorageFactory $loader
	 */
	private static $loader;

	/** @var bool */
	private static $logWarningWhenAddingStorageWrapper = true;

	/**
	 * @param bool $shouldLog
	 * @return bool previous value
	 * @internal
	 */
	public static function logWarningWhenAddingStorageWrapper($shouldLog) {
		$previousValue = self::$logWarningWhenAddingStorageWrapper;
		self::$logWarningWhenAddingStorageWrapper = (bool) $shouldLog;
		return $previousValue;
	}

	/**
	 * @param string $wrapperName
	 * @param callable $wrapper
	 * @param int $priority
	 */
	public static function addStorageWrapper($wrapperName, $wrapper, $priority = 50) {
		if (self::$logWarningWhenAddingStorageWrapper) {
			\OC::$server->getLogger()->warning("Storage wrapper '{wrapper}' was not registered via the 'OC_Filesystem - preSetup' hook which could cause potential problems.", [
				'wrapper' => $wrapperName,
				'app' => 'filesystem',
			]);
		}

		$mounts = self::getMountManager()->getAll();
		if (!self::getLoader()->addStorageWrapper($wrapperName, $wrapper, $priority, $mounts)) {
			// do not re-wrap if storage with this name already existed
			return;
		}
	}

	/**
	 * Returns the storage factory
	 *
	 * @return \OCP\Files\Storage\IStorageFactory
	 */
	public static function getLoader() {
		if (!self::$loader) {
			self::$loader = new StorageFactory();
		}
		return self::$loader;
	}

	/**
	 * Returns the mount manager
	 *
	 * @return \OC\Files\Mount\Manager
	 */
	public static function getMountManager($user = '') {
		if (!self::$mounts) {
			\OC_Util::setupFS($user);
		}
		return self::$mounts;
	}

	/**
	 * get the mountpoint of the storage object for a path
	 * ( note: because a storage is not always mounted inside the fakeroot, the
	 * returned mountpoint is relative to the absolute root of the filesystem
	 * and doesn't take the chroot into account )
	 *
	 * @param string $path
	 * @return string
	 */
	static public function getMountPoint($path) {
		if (!self::$mounts) {
			\OC_Util::setupFS();
		}
		$mount = self::$mounts->find($path);
		if ($mount) {
			return $mount->getMountPoint();
		} else {
			return '';
		}
	}

	/**
	 * get a list of all mount points in a directory
	 *
	 * @param string $path
	 * @return string[]
	 */
	static public function getMountPoints($path) {
		if (!self::$mounts) {
			\OC_Util::setupFS();
		}
		$result = [];
		$mounts = self::$mounts->findIn($path);
		foreach ($mounts as $mount) {
			$result[] = $mount->getMountPoint();
		}
		return $result;
	}

	/**
	 * get the storage mounted at $mountPoint
	 *
	 * @param string $mountPoint
	 * @return \OC\Files\Storage\Storage
	 */
	public static function getStorage($mountPoint) {
		if (!self::$mounts) {
			\OC_Util::setupFS();
		}
		$mount = self::$mounts->find($mountPoint);
		return $mount->getStorage();
	}

	/**
	 * @param string $id
	 * @return Mount\MountPoint[]
	 */
	public static function getMountByStorageId($id) {
		if (!self::$mounts) {
			\OC_Util::setupFS();
		}
		return self::$mounts->findByStorageId($id);
	}

	/**
	 * @param int $id
	 * @return Mount\MountPoint[]
	 */
	public static function getMountByNumericId($id) {
		if (!self::$mounts) {
			\OC_Util::setupFS();
		}
		return self::$mounts->findByNumericId($id);
	}

	/**
	 * resolve a path to a storage and internal path
	 *
	 * @param string $path
	 * @return array an array consisting of the storage and the internal path
	 */
	static public function resolvePath($path) {
		if (!self::$mounts) {
			\OC_Util::setupFS();
		}
		$mount = self::$mounts->find($path);
		if ($mount) {
			return [$mount->getStorage(), rtrim($mount->getInternalPath($path), '/')];
		} else {
			return [null, null];
		}
	}

	static public function init($user, $root) {
		if (self::$defaultInstance) {
			return false;
		}
		self::getLoader();
		self::$defaultInstance = new View($root);

		if (!self::$mounts) {
			self::$mounts = \OC::$server->getMountManager();
		}

		//load custom mount config
		self::initMountPoints($user);

		self::$loaded = true;

		return true;
	}

	static public function initMountManager() {
		if (!self::$mounts) {
			self::$mounts = \OC::$server->getMountManager();
		}
	}

	/**
	 * Initialize system and personal mount points for a user
	 *
	 * @param string $user
	 * @throws \OC\User\NoUserException if the user is not available
	 */
	public static function initMountPoints($user = '') {
		if ($user == '') {
			$user = \OC_User::getUser();
		}
		if ($user === null || $user === false || $user === '') {
			throw new \OC\User\NoUserException('Attempted to initialize mount points for null user and no user in session');
		}

		if (isset(self::$usersSetup[$user])) {
			return;
		}

		self::$usersSetup[$user] = true;

		$userManager = \OC::$server->getUserManager();
		$userObject = $userManager->get($user);

		if (is_null($userObject)) {
			\OCP\Util::writeLog('files', ' Backends provided no user object for ' . $user, \OCP\Util::ERROR);
			// reset flag, this will make it possible to rethrow the exception if called again
			unset(self::$usersSetup[$user]);
			throw new \OC\User\NoUserException('Backends provided no user object for ' . $user);
		}

		$realUid = $userObject->getUID();
		// workaround in case of different casings
		if ($user !== $realUid) {
			$stack = json_encode(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 50));
			\OCP\Util::writeLog('files', 'initMountPoints() called with wrong user casing. This could be a bug. Expected: "' . $realUid . '" got "' . $user . '". Stack: ' . $stack, \OCP\Util::WARN);
			$user = $realUid;

			// again with the correct casing
			if (isset(self::$usersSetup[$user])) {
				return;
			}

			self::$usersSetup[$user] = true;
		}

		/** @var \OC\Files\Config\MountProviderCollection $mountConfigManager */
		$mountConfigManager = \OC::$server->getMountProviderCollection();

		// home mounts are handled seperate since we need to ensure this is mounted before we call the other mount providers
		$homeMount = $mountConfigManager->getHomeMountForUser($userObject);

		self::getMountManager()->addMount($homeMount);

		\OC\Files\Filesystem::getStorage($user);

		// Chance to mount for other storages
		if ($userObject) {
			$mounts = $mountConfigManager->getMountsForUser($userObject);
			array_walk($mounts, [self::$mounts, 'addMount']);
			$mounts[] = $homeMount;
			$mountConfigManager->registerMounts($userObject, $mounts);
		}

		self::listenForNewMountProviders($mountConfigManager, $userManager);
		\OC_Hook::emit('OC_Filesystem', 'post_initMountPoints', ['user' => $user]);
	}

	/**
	 * Get mounts from mount providers that are registered after setup
	 *
	 * @param MountProviderCollection $mountConfigManager
	 * @param IUserManager $userManager
	 */
	private static function listenForNewMountProviders(MountProviderCollection $mountConfigManager, IUserManager $userManager) {
		if (!self::$listeningForProviders) {
			self::$listeningForProviders = true;
			$mountConfigManager->listen('\OC\Files\Config', 'registerMountProvider', function (IMountProvider $provider) use ($userManager) {
				foreach (Filesystem::$usersSetup as $user => $setup) {
					$userObject = $userManager->get($user);
					if ($userObject) {
						$mounts = $provider->getMountsForUser($userObject, Filesystem::getLoader());
						array_walk($mounts, [self::$mounts, 'addMount']);
					}
				}
			});
		}
	}

	/**
	 * get the default filesystem view
	 *
	 * @return View
	 */
	static public function getView() {
		return self::$defaultInstance;
	}

	/**
	 * tear down the filesystem, removing all storage providers
	 */
	static public function tearDown() {
		self::clearMounts();
		self::$defaultInstance = null;
	}

	/**
	 * get the relative path of the root data directory for the current user
	 *
	 * @return string
	 *
	 * Returns path like /admin/files
	 */
	static public function getRoot() {
		if (!self::$defaultInstance) {
			return null;
		}
		return self::$defaultInstance->getRoot();
	}

	/**
	 * clear all mounts and storage backends
	 */
	public static function clearMounts() {
		if (self::$mounts) {
			self::$usersSetup = [];
			self::$mounts->clear();
		}
	}

	/**
	 * mount an \OC\Files\Storage\Storage in our virtual filesystem
	 *
	 * @param \OC\Files\Storage\Storage|string $class
	 * @param array $arguments
	 * @param string $mountpoint
	 */
	static public function mount($class, $arguments, $mountpoint) {
		if (!self::$mounts) {
			\OC_Util::setupFS();
		}
		$mount = new Mount\MountPoint($class, $mountpoint, $arguments, self::getLoader());
		self::$mounts->addMount($mount);
	}

	/**
	 * return the path to a local version of the file
	 * we need this because we can't know if a file is stored local or not from
	 * outside the filestorage and for some purposes a local file is needed
	 *
	 * @param string $path
	 * @return string
	 */
	static public function getLocalFile($path) {
		return self::$defaultInstance->getLocalFile($path);
	}

	/**
	 * @param string $path
	 * @return string
	 */
	static public function getLocalFolder($path) {
		return self::$defaultInstance->getLocalFolder($path);
	}

	/**
	 * return path to file which reflects one visible in browser
	 *
	 * @param string $path
	 * @return string
	 */
	static public function getLocalPath($path) {
		$datadir = \OC_User::getHome(\OC_User::getUser()) . '/files';
		$newpath = $path;
		if (strncmp($newpath, $datadir, strlen($datadir)) == 0) {
			$newpath = substr($path, strlen($datadir));
		}
		return $newpath;
	}

	/**
	 * check if the requested path is valid
	 *
	 * @param string $path
	 * @return bool
	 */
	static public function isValidPath($path) {
		$path = self::normalizePath($path);
		if (!$path || $path[0] !== '/') {
			$path = '/' . $path;
		}
		if (strpos($path, '/../') !== false || strrchr($path, '/') === '/..') {
			return false;
		}
		return true;
	}

	/**
	 * checks if a file is blacklisted for storage in the filesystem
	 * Listens to write and rename hooks
	 *
	 * @param array $data from hook
	 */
	static public function isForbiddenFileOrDir_Hook($data) {
		if (isset($data['path'])) {
			$path = $data['path'];
		} else if (isset($data['newpath'])) {
			$path = $data['newpath'];
		}
		if (isset($path)) {
			if (self::isForbiddenFileOrDir($path)) {
				$data['run'] = false;
			}
		}
	}

	/**
	 * depriciated, replaced by isForbiddenFileOrDir
	 * @param string $filename
	 * @return boolean
	 */
	static public function isFileBlacklisted($filename) {
		return self::isForbiddenFileOrDir($filename);
	}

	/**
	 * check if the directory should be ignored when scanning
	 * NOTE: the special directories . and .. would cause never ending recursion
	 *
	 * @param String $dir
	 * @return boolean
	 */
	static public function isIgnoredDir($dir) {
		if ($dir === '.' || $dir === '..') {
			return true;
		}
		return false;
	}

	/**
	* Check if the directory path / file name contains a Blacklisted or Excluded name
	* config.php parameter arrays can contain file names to be blacklisted or directory names to be excluded
	* Blacklist ... files that may harm the owncloud environment like a foreign .htaccess file
	* Excluded  ... directories that are excluded from beeing further processed, like snapshot directories
	* The parameter $ed is only used in conjunction with unit tests as we handover here the excluded
	* directory name to be tested against. $ed and the query with can be redesigned if filesystem.php will get 
	* a constructor where it is then possible to define the excluded directory names for unit tests.
	* @param string $FileOrDir
	* @param array $ed
	* @return boolean
	*/
	static public function isForbiddenFileOrDir($FileOrDir, $ed = []) {
		$excluded = [];
		$blacklist = [];
		$path_parts = [];
		$ppx = [];
		$blacklist = \OC::$server->getSystemConfig()->getValue('blacklisted_files', ['.htaccess']);
		if ($ed) {
			$excluded = $ed;
		} else {
			$excluded = \OC::$server->getSystemConfig()->getValue('excluded_directories', $ed);
		}
		// explode '/'
		$ppx = array_filter(explode('/', $FileOrDir), 'strlen');
		$ppx = array_map('strtolower', $ppx);
		// further explode each array element with '\' and add to result array if found  
		foreach($ppx as $pp) {
			// only add an array element if strlen != 0
			$path_parts = array_merge($path_parts, array_filter(explode('\\', $pp), 'strlen'));
		}
		if ($excluded) {
			$excluded = array_map('trim', $excluded);
			$excluded = array_map('strtolower', $excluded);
			$match = array_intersect($path_parts, $excluded);
			if ($match) {
				return true;
			}
		}
		$blacklist = array_map('trim', $blacklist);
		$blacklist = array_map('strtolower', $blacklist);
		$match = array_intersect($path_parts, $blacklist);
		if ($match) {
			return true;
		}
		return false;
	}

	/**
	 * following functions are equivalent to their php builtin equivalents for arguments/return values.
	 */
	static public function mkdir($path) {
		return self::$defaultInstance->mkdir($path);
	}

	static public function rmdir($path) {
		return self::$defaultInstance->rmdir($path);
	}

	static public function opendir($path) {
		return self::$defaultInstance->opendir($path);
	}

	static public function is_dir($path) {
		return self::$defaultInstance->is_dir($path);
	}

	static public function is_file($path) {
		return self::$defaultInstance->is_file($path);
	}

	static public function stat($path) {
		return self::$defaultInstance->stat($path);
	}

	static public function filetype($path) {
		return self::$defaultInstance->filetype($path);
	}

	static public function filesize($path) {
		return self::$defaultInstance->filesize($path);
	}

	static public function readfile($path) {
		return self::$defaultInstance->readfile($path);
	}

	static public function isCreatable($path) {
		return self::$defaultInstance->isCreatable($path);
	}

	static public function isReadable($path) {
		return self::$defaultInstance->isReadable($path);
	}

	static public function isUpdatable($path) {
		return self::$defaultInstance->isUpdatable($path);
	}

	static public function isDeletable($path) {
		return self::$defaultInstance->isDeletable($path);
	}

	static public function isSharable($path) {
		return self::$defaultInstance->isSharable($path);
	}

	static public function file_exists($path) {
		return self::$defaultInstance->file_exists($path);
	}

	static public function filemtime($path) {
		return self::$defaultInstance->filemtime($path);
	}

	static public function touch($path, $mtime = null) {
		return self::$defaultInstance->touch($path, $mtime);
	}

	/**
	 * @return string
	 */
	static public function file_get_contents($path) {
		return self::$defaultInstance->file_get_contents($path);
	}

	static public function file_put_contents($path, $data) {
		return self::$defaultInstance->file_put_contents($path, $data);
	}

	static public function unlink($path) {
		return self::$defaultInstance->unlink($path);
	}

	static public function rename($path1, $path2) {
		return self::$defaultInstance->rename($path1, $path2);
	}

	static public function copy($path1, $path2) {
		return self::$defaultInstance->copy($path1, $path2);
	}

	static public function fopen($path, $mode) {
		return self::$defaultInstance->fopen($path, $mode);
	}

	/**
	 * @return string
	 */
	static public function toTmpFile($path) {
		return self::$defaultInstance->toTmpFile($path);
	}

	static public function fromTmpFile($tmpFile, $path) {
		return self::$defaultInstance->fromTmpFile($tmpFile, $path);
	}

	static public function getMimeType($path) {
		return self::$defaultInstance->getMimeType($path);
	}

	static public function hash($type, $path, $raw = false) {
		return self::$defaultInstance->hash($type, $path, $raw);
	}

	static public function free_space($path = '/') {
		return self::$defaultInstance->free_space($path);
	}

	static public function search($query) {
		return self::$defaultInstance->search($query);
	}

	/**
	 * @param string $query
	 */
	static public function searchByMime($query) {
		return self::$defaultInstance->searchByMime($query);
	}

	/**
	 * @param string|int $tag name or tag id
	 * @param string $userId owner of the tags
	 * @return FileInfo[] array or file info
	 */
	static public function searchByTag($tag, $userId) {
		return self::$defaultInstance->searchByTag($tag, $userId);
	}

	/**
	 * check if a file or folder has been updated since $time
	 *
	 * @param string $path
	 * @param int $time
	 * @return bool
	 */
	static public function hasUpdated($path, $time) {
		return self::$defaultInstance->hasUpdated($path, $time);
	}

	/**
	 * Fix common problems with a file path
	 *
	 * @param string $path
	 * @param bool $stripTrailingSlash whether to strip the trailing slash
	 * @param bool $isAbsolutePath whether the given path is absolute
	 * @param bool $keepUnicode true to disable unicode normalization
	 * @return string
	 */
	public static function normalizePath($path, $stripTrailingSlash = true, $isAbsolutePath = false, $keepUnicode = false) {
		if (is_null(self::$normalizedPathCache)) {
			self::$normalizedPathCache = new CappedMemoryCache();
		}

		/**
		 * FIXME: This is a workaround for existing classes and files which call
		 *        this function with another type than a valid string. This
		 *        conversion should get removed as soon as all existing
		 *        function calls have been fixed.
		 */
		$path = (string)$path;

		$cacheKey = json_encode([$path, $stripTrailingSlash, $isAbsolutePath, $keepUnicode]);

		if (isset(self::$normalizedPathCache[$cacheKey])) {
			return self::$normalizedPathCache[$cacheKey];
		}

		if ($path == '') {
			return '/';
		}

		//normalize unicode if possible
		if (!$keepUnicode) {
			$path = \OC_Util::normalizeUnicode($path);
		}

		//no windows style slashes
		$path = str_replace('\\', '/', $path);

		//add leading slash
		if ($path[0] !== '/') {
			$path = '/' . $path;
		}

		// remove '/./'
		// ugly, but str_replace() can't replace them all in one go
		// as the replacement itself is part of the search string
		// which will only be found during the next iteration
		while (strpos($path, '/./') !== false) {
			$path = str_replace('/./', '/', $path);
		}
		// remove sequences of slashes
		$path = preg_replace('#/{2,}#', '/', $path);

		//remove trailing slash
		if ($stripTrailingSlash and strlen($path) > 1 and substr($path, -1, 1) === '/') {
			$path = substr($path, 0, -1);
		}

		// remove trailing '/.'
		if (substr($path, -2) == '/.') {
			$path = substr($path, 0, -2);
		}

		$normalizedPath = $path;
		self::$normalizedPathCache[$cacheKey] = $normalizedPath;

		return $normalizedPath;
	}

	/**
	 * get the filesystem info
	 *
	 * @param string $path
	 * @param boolean $includeMountPoints whether to add mountpoint sizes,
	 * defaults to true
	 * @return \OC\Files\FileInfo|bool False if file does not exist
	 */
	public static function getFileInfo($path, $includeMountPoints = true) {
		return self::$defaultInstance->getFileInfo($path, $includeMountPoints);
	}

	/**
	 * change file metadata
	 *
	 * @param string $path
	 * @param array $data
	 * @return int
	 *
	 * returns the fileid of the updated file
	 */
	public static function putFileInfo($path, $data) {
		return self::$defaultInstance->putFileInfo($path, $data);
	}

	/**
	 * get the content of a directory
	 *
	 * @param string $directory path under datadirectory
	 * @param string $mimetype_filter limit returned content to this mimetype or mimepart
	 * @return \OC\Files\FileInfo[]
	 */
	public static function getDirectoryContent($directory, $mimetype_filter = '') {
		return self::$defaultInstance->getDirectoryContent($directory, $mimetype_filter);
	}

	/**
	 * Get the path of a file by id
	 *
	 * Note that the resulting path is not guaranteed to be unique for the id, multiple paths can point to the same file
	 *
	 * @param int $id
	 * @throws NotFoundException
	 * @return string
	 */
	public static function getPath($id) {
		if (self::$defaultInstance === null) {
			throw new NotFoundException("defaultInstance is null");
		}
		return self::$defaultInstance->getPath($id);
	}

	/**
	 * Get the owner for a file or folder
	 *
	 * @param string $path
	 * @return string
	 */
	public static function getOwner($path) {
		return self::$defaultInstance->getOwner($path);
	}

	/**
	 * get the ETag for a file or folder
	 *
	 * @param string $path
	 * @return string
	 */
	static public function getETag($path) {
		return self::$defaultInstance->getETag($path);
	}
}
