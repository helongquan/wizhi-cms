<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

namespace Nette\Loaders;

use Nette;
use SplFileInfo;


/**
 * Nette auto loader is responsible for loading classes and interfaces.
 *
 * <code>
 * $loader = new Nette\Loaders\RobotLoader;
 * $loader->addDirectory('app');
 * $loader->excludeDirectory('app/exclude');
 * $loader->setTempDirectory('temp');
 * $loader->register();
 * </code>
 */
class RobotLoader
{
	use Nette\SmartObject;

	const RETRY_LIMIT = 3;

	/** @var string|array  comma separated wildcards */
	public $ignoreDirs = '.*, *.old, *.bak, *.tmp, temp';

	/** @var string|array  comma separated wildcards */
	public $acceptFiles = '*.php';

	/** @var bool */
	private $autoRebuild = TRUE;

	/** @var array */
	private $scanPaths = [];

	/** @var array */
	private $excludeDirs = [];

	/** @var array of class => [file, time] */
	private $classes = [];

	/** @var bool */
	private $refreshed = FALSE;

	/** @var array of missing classes */
	private $missing = [];

	/** @var string|NULL */
	private $tempDirectory;


	public function __construct()
	{
		if (!extension_loaded('tokenizer')) {
			throw new Nette\NotSupportedException('PHP extension Tokenizer is not loaded.');
		}
	}


	/**
	 * Register autoloader.
	 * @param  bool  prepend autoloader?
	 * @return static
	 */
	public function register($prepend = FALSE)
	{
		$this->loadCache();
		spl_autoload_register([$this, 'tryLoad'], TRUE, (bool) $prepend);
		return $this;
	}


	/**
	 * Handles autoloading of classes, interfaces or traits.
	 * @param  string
	 * @return void
	 */
	public function tryLoad($type)
	{
		$type = ltrim($type, '\\'); // PHP namespace bug #49143
		$info = isset($this->classes[$type]) ? $this->classes[$type] : NULL;

		if ($this->autoRebuild) {
			if (!$info || !is_file($info['file'])) {
				$missing = & $this->missing[$type];
				$missing++;
				if (!$this->refreshed && $missing <= self::RETRY_LIMIT) {
					$this->refresh();
					$this->saveCache();
				} elseif ($info) {
					unset($this->classes[$type]);
					$this->saveCache();
				}

			} elseif (!$this->refreshed && filemtime($info['file']) !== $info['time']) {
				$this->updateFile($info['file']);
				if (empty($this->classes[$type])) {
					$this->missing[$type] = 0;
				}
				$this->saveCache();
			}
			$info = isset($this->classes[$type]) ? $this->classes[$type] : NULL;
		}

		if ($info) {
			call_user_func(function ($file) { require $file; }, $info['file']);
		}
	}


	/**
	 * Add path or paths to list.
	 * @param  string|string[]  absolute path
	 * @return static
	 */
	public function addDirectory($path)
	{
		$this->scanPaths = array_merge($this->scanPaths, (array) $path);
		return $this;
	}


	/**
	 * Excludes path or paths from list.
	 * @param  string|string[]  absolute path
	 * @return static
	 */
	public function excludeDirectory($path)
	{
		$this->excludeDirs = array_merge($this->excludeDirs, (array) $path);
		return $this;
	}


	/**
	 * @return array of class => filename
	 */
	public function getIndexedClasses()
	{
		$res = [];
		foreach ($this->classes as $class => $info) {
			$res[$class] = $info['file'];
		}
		return $res;
	}


	/**
	 * Rebuilds class list cache.
	 * @return void
	 */
	public function rebuild()
	{
		$this->refresh();
		if ($this->tempDirectory) {
			$this->saveCache();
		}
	}


	/**
	 * Refreshes class list.
	 * @return void
	 */
	private function refresh()
	{
		$this->refreshed = TRUE; // prevents calling refresh() or updateFile() in tryLoad()
		$files = [];
		foreach ($this->classes as $class => $info) {
			$files[$info['file']]['time'] = $info['time'];
			$files[$info['file']]['classes'][] = $class;
		}

		$this->classes = [];
		foreach ($this->scanPaths as $path) {
			foreach (is_file($path) ? [new SplFileInfo($path)] : $this->createFileIterator($path) as $file) {
				$file = $file->getPathname();
				if (isset($files[$file]) && $files[$file]['time'] == filemtime($file)) {
					$classes = $files[$file]['classes'];
				} else {
					$classes = $this->scanPhp(file_get_contents($file));
				}
				$files[$file] = ['classes' => [], 'time' => filemtime($file)];

				foreach ($classes as $class) {
					$info = &$this->classes[$class];
					if (isset($info['file'])) {
						throw new Nette\InvalidStateException("Ambiguous class $class resolution; defined in {$info['file']} and in $file.");
					}
					$info = ['file' => $file, 'time' => filemtime($file)];
					unset($this->missing[$class]);
				}
			}
		}
	}


	/**
	 * Creates an iterator scaning directory for PHP files, subdirectories and 'netterobots.txt' files.
	 * @return \Iterator
	 * @throws Nette\IOException if path is not found
	 */
	private function createFileIterator($dir)
	{
		if (!is_dir($dir)) {
			throw new Nette\IOException("File or directory '$dir' not found.");
		}

		$ignoreDirs = is_array($this->ignoreDirs) ? $this->ignoreDirs : preg_split('#[,\s]+#', $this->ignoreDirs);
		$disallow = [];
		foreach (array_merge($ignoreDirs, $this->excludeDirs) as $item) {
			if ($item = realpath($item)) {
				$disallow[str_replace('\\', '/', $item)] = TRUE;
			}
		}

		$iterator = Nette\Utils\Finder::findFiles(is_array($this->acceptFiles) ? $this->acceptFiles : preg_split('#[,\s]+#', $this->acceptFiles))
			->filter(function (SplFileInfo $file) use (&$disallow) {
				return !isset($disallow[str_replace('\\', '/', $file->getPathname())]);
			})
			->from($dir)
			->exclude($ignoreDirs)
			->filter($filter = function (SplFileInfo $dir) use (&$disallow) {
				$path = str_replace('\\', '/', $dir->getPathname());
				if (is_file("$path/netterobots.txt")) {
					foreach (file("$path/netterobots.txt") as $s) {
						if (preg_match('#^(?:disallow\\s*:)?\\s*(\\S+)#i', $s, $matches)) {
							$disallow[$path . rtrim('/' . ltrim($matches[1], '/'), '/')] = TRUE;
						}
					}
				}
				return !isset($disallow[$path]);
			});

		$filter(new SplFileInfo($dir));
		return $iterator;
	}


	/**
	 * @return void
	 */
	private function updateFile($file)
	{
		foreach ($this->classes as $class => $info) {
			if (isset($info['file']) && $info['file'] === $file) {
				unset($this->classes[$class]);
			}
		}

		$classes = is_file($file) ? $this->scanPhp(file_get_contents($file)) : [];
		foreach ($classes as $class) {
			$info = &$this->classes[$class];
			if (isset($info['file']) && @filemtime($info['file']) !== $info['time']) { // @ file may not exists
				$this->updateFile($info['file']);
				$info = &$this->classes[$class];
			}
			if (isset($info['file'])) {
				throw new Nette\InvalidStateException("Ambiguous class $class resolution; defined in {$info['file']} and in $file.");
			}
			$info = ['file' => $file, 'time' => filemtime($file)];
		}
	}


	/**
	 * Searches classes, interfaces and traits in PHP file.
	 * @param  string
	 * @return string[]
	 */
	private function scanPhp($code)
	{
		$expected = FALSE;
		$namespace = '';
		$level = $minLevel = 0;
		$classes = [];

		if (preg_match('#//nette'.'loader=(\S*)#', $code, $matches)) {
			foreach (explode(',', $matches[1]) as $name) {
				$classes[] = $name;
			}
			return $classes;
		}

		foreach (@token_get_all($code) as $token) { // @ can be corrupted or can use newer syntax
			if (is_array($token)) {
				switch ($token[0]) {
					case T_COMMENT:
					case T_DOC_COMMENT:
					case T_WHITESPACE:
						continue 2;

					case T_NS_SEPARATOR:
					case T_STRING:
						if ($expected) {
							$name .= $token[1];
						}
						continue 2;

					case T_NAMESPACE:
					case T_CLASS:
					case T_INTERFACE:
					case T_TRAIT:
						$expected = $token[0];
						$name = '';
						continue 2;
					case T_CURLY_OPEN:
					case T_DOLLAR_OPEN_CURLY_BRACES:
						$level++;
				}
			}

			if ($expected) {
				switch ($expected) {
					case T_CLASS:
					case T_INTERFACE:
					case T_TRAIT:
						if ($name && $level === $minLevel) {
							$classes[] = $namespace . $name;
						}
						break;

					case T_NAMESPACE:
						$namespace = $name ? $name . '\\' : '';
						$minLevel = $token === '{' ? 1 : 0;
				}

				$expected = NULL;
			}

			if ($token === '{') {
				$level++;
			} elseif ($token === '}') {
				$level--;
			}
		}
		return $classes;
	}


	/********************* caching ****************d*g**/


	/**
	 * Sets auto-refresh mode.
	 * @return static
	 */
	public function setAutoRefresh($on = TRUE)
	{
		$this->autoRebuild = (bool) $on;
		return $this;
	}


	/**
	 * Sets path to temporary directory.
	 * @return static
	 */
	public function setTempDirectory($dir)
	{
		if (!is_dir($dir)) {
			@mkdir($dir); // @ - directory may already exist
		}
		$this->tempDirectory = $dir;
		return $this;
	}


	/**
	 * Loads class list from cache.
	 * @return void
	 */
	private function loadCache()
	{
		$file = $this->getCacheFile();
		list($this->classes, $this->missing) = @include $file; // @ file may not exist
		if (is_array($this->classes)) {
			return;
		}

		$handle = fopen("$file.lock", 'c+');
		if (!$handle || !flock($handle, LOCK_EX)) {
			throw new \RuntimeException("Unable to create or acquire exclusive lock on file '$file.lock'.");
		}

		list($this->classes, $this->missing) = @include $file; // @ file may not exist
		if (!is_array($this->classes)) {
			$this->classes = [];
			$this->refresh();
			$this->saveCache();
		}

		flock($handle, LOCK_UN);
		fclose($handle);
		@unlink("$file.lock"); // @ file may become locked on Windows
	}


	/**
	 * Writes class list to cache.
	 * @return void
	 */
	private function saveCache()
	{
		$file = $this->getCacheFile();
		$code = "<?php\nreturn " . var_export([$this->classes, $this->missing], TRUE) . ";\n";
		if (file_put_contents("$file.tmp", $code) !== strlen($code) || !rename("$file.tmp", $file)) {
			@unlink("$file.tmp"); // @ - file may not exist
			throw new \RuntimeException("Unable to create '$file'.");
		}
		if (function_exists('opcache_invalidate')) {
			@opcache_invalidate($file, TRUE); // @ can be restricted
		}
	}


	/**
	 * @return string
	 */
	private function getCacheFile()
	{
		if (!$this->tempDirectory) {
			throw new \LogicException('Set path to temporary directory using setTempDirectory().');
		}
		return $this->tempDirectory . '/' . md5(serialize($this->getCacheKey())) . '.php';
	}


	/**
	 * @return array
	 */
	protected function getCacheKey()
	{
		return [$this->ignoreDirs, $this->acceptFiles, $this->scanPaths, $this->excludeDirs];
	}

}
