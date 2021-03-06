<?php namespace Artdevue\Fcache;

/**
 * Created by PhpStorm.
 * User: AtrDevue
 * Date: 25.06.14
 * Time: 10:36
 */
use Illuminate\Cache\StoreInterface,
    Illuminate\Filesystem\Filesystem,
    Closure;

class Fcache implements StoreInterface
{

    /**
     * The Illuminate Filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * A string that should be prepended to keys.
     *
     * @var string
     */
    protected $prefix;

    /**
     * A string tags.
     *
     * @var string
     */
    protected $tags;

    /**
     * The file cache directory
     *
     * @var string
     */
    protected $directory;

    /**
     * The file cache directory for Tags
     *
     * @var string
     */
    protected $directoryTags;

    public $memory = array();


    /**
     * Create a new Dummy cache store.
     *
     * @param  string $prefix
     * @return void
     */
    public function __construct($prefix = '')
    {
        $this->files = new Filesystem;
        $this->prefix = $prefix;
        $this->directory = \Config::get('cache.path') . '/' . app()->environment();
        $this->tags = array();
        $this->directoryTags = $this->directory . (!empty($prefix) ? '/' . $prefix : '') . '/tags';
    }

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  string $key
     * @return mixed
     */
    public function get($key)
    {


        $mem_key = $key;
        if (!isset($this->memory[$mem_key])) {
            $path = $this->path($key);


            if (!$this->files->exists($path)) {


                return null;
            }

            try {
                $expire = substr($contents = $this->files->get($path), 0, 10);
            } catch (\Exception $e) {
                return null;
            }

            // If the current time is greater than expiration timestamps we will delete
            // the file and return null. This helps clean up the old files and keeps
            // this directory much cleaner for us as old files aren't hanging out.
            if (time() >= $expire) {
                return $this->forget($key);
            }
            $this->memory[$mem_key] = unserialize(substr($contents, 10));
        }

        return $this->memory[$mem_key];
    }

    /**
     * Store an item in the cache for a given number of minutes.
     *
     * @param  string $key
     * @param  mixed $value
     * @param  int $minutes
     * @return void
     */
    public function put($key, $value, $minutes)
    {
        $value = $this->expiration($minutes) . serialize($value);

        $this->createCacheDirectory($path = $this->path($key));

        if (!empty($this->tags)) {
            $this->_setTags($path);
        }

        $path = $this->normalize_path($path, false);


        $this->files->put($path, $value);
    }

    /**
     * Tags for cache.
     *
     * @param  string $string
     * @return object
     */
    public function tags($tags)
    {
        if (is_string($tags))
            $string_array = explode(',', $tags);
        else if (is_array($tags))
            $string_array = $tags;
        array_walk($string_array, 'trim');

        $this->tags = $string_array;

        return $this;
    }

    /**
     * Save Tags for cache.
     *
     * @param  string $path
     * @return null
     */
    private function _setTags($path)
    {

        foreach ($this->tags as $tg) {
            $file = $this->directoryTags . '/' . $tg;
            $file = $this->normalize_path($file, false);

            if (!$this->files->exists($file)) {
                $this->createCacheDirectory($file);
                $this->files->put($file, $path);


            } else {
                $farr = file($file);
                if (!in_array($path, $farr)) {
                    file_put_contents($file, "\n$path", FILE_APPEND);
                }
            }

        }
        // $this->tags = array();
    }

    /**
     * Get an item from the cache, or store the default value.
     *
     * @param  string $key
     * @param  \DateTime|int $minutes
     * @param  Closure $callback
     * @return mixed
     */
    public function remember($key, $minutes, Closure $callback)
    {
        // If the item exists in the cache we will just return this immediately
        // otherwise we will execute the given Closure and cache the result
        // of that execution for the given number of minutes in storage.
        if (!is_null($value = $this->get($key))) {
            return $value;
        }

        $this->put($key, $value = $callback(), $minutes);

        return $value;
    }

    /**
     * Get an item from the cache, or store the default value forever.
     *
     * @param  string $key
     * @param  Closure $callback
     * @return mixed
     */
    public function rememberForever($key, Closure $callback)
    {
        // If the item exists in the cache we will just return this immediately
        // otherwise we will execute the given Closure and cache the result
        // of that execution for the given number of minutes. It's easy.
        if (!is_null($value = $this->get($key))) {
            return $value;
        }

        $this->forever($key, $value = $callback());

        return $value;
    }

    /**
     * Create the file cache directory if necessary.
     *
     * @param  string $path
     * @return void
     */
    protected function createCacheDirectory($path)
    {
        try {
            $this->files->makeDirectory(dirname($path), 0777, true, true);
        } catch (\Exception $e) {
            //
        }
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string $key
     * @param  mixed $value
     * @return void
     *
     * @throws \LogicException
     */
    public function increment($key, $value = 1)
    {
        throw new \LogicException("Not supported by this driver.");
    }

    /**
     * Increment the value of an item in the cache.
     *
     * @param  string $key
     * @param  mixed $value
     * @return void
     *
     * @throws \LogicException
     */
    public function decrement($key, $value = 1)
    {
        throw new \LogicException("Not supported by this driver.");
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string $key
     * @param  mixed $value
     * @return void
     */
    public function forever($key, $value)
    {
        return $this->put($key, $value, 0);
    }

    /**
     * Remove an item from the cache by tags.
     *
     * @param  string $string
     * @return void
     */
    public function forgetTags($string)
    {
        $string_array = explode(',', $string);
        array_walk($string_array, 'trim');

        foreach ($string_array as $sa) {
            $file = $this->directoryTags . '/' . $sa;
            if ($this->files->exists($file)) {
                $farr = file($file);
                foreach ($farr as $f) {
                    $f = $this->normalize_path($f, false);
                    if ($this->files->exists($f)) {

                        unlink($f);
                    } else {

                    }

                }
                unlink($file);
            }
        }
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string $key
     * @return void
     */
    public function forget($key)
    {



        $file = $this->path($key);

        if ($this->files->exists($file)) {
            $this->files->delete($file);
        } else {
            $folder = substr($file, 0, -6);
            if ($this->files->exists($folder)) {
                $this->files->deleteDirectory($folder);
            }
        }

        $this->memory = array();
    }

    /**
     * Remove all items from the cache.
     *
     * @param  string $tag
     * @return void
     */
    public function flush()
    {
        if (empty($this->tags)) {
            foreach ($this->files->directories($this->directory) as $directory) {
                $this->files->deleteDirectory($directory);
            }
        }

        foreach ($this->tags as $tag) {
            $items = $this->forgetTags($tag);
            $del = $this->directory . '/' . $tag;
            $del = $this->normalize_path($del);
            $this->files->deleteDirectory($del);
        }

        $this->memory = array();
    }

    /**
     * Get the Filesystem instance.
     *
     * @return \Illuminate\Filesystem\Filesystem
     */
    public function getFilesystem()
    {
        return $this->files;
    }

    /**
     * Get the working directory of the cache.
     *
     * @return string
     */
    public function getDirectory()
    {
        return $this->directory;
    }

    /**
     * Get the cache key prefix.
     *
     * @return string
     */
    public function getPrefix()
    {
        return $this->prefix;
    }

    /**
     * Get the full path for the given cache key.
     *
     * @param  string $key
     * @return string
     */
    protected function path($key)
    {

        $prefix = !empty($this->prefix) ? $this->prefix . '/' : '';
        $subdir = 'global';
        if (!empty($this->tags)) {
            $subdir = reset($this->tags);
        }


        return $this->directory . '/' . $subdir . '/' . $prefix . trim($key, "/") . '.cache';
    }

    /**
     * Get the expiration time based on the given minutes.
     *
     * @param  int $minutes
     * @return int
     */
    protected function expiration($minutes)
    {
        if ($minutes === 0) return 9999999999;

        return time() + ($minutes * 60);
    }


    function normalize_path($path, $slash_it = true)
    {
        $path_original = $path;
        $s = DIRECTORY_SEPARATOR;
        $path = preg_replace('/[\/\\\]/', $s, $path);
        $path = str_replace($s . $s, $s, $path);
        if (strval($path) == '') {
            $path = $path_original;
        }
        if ($slash_it == false) {
            $path = rtrim($path, DIRECTORY_SEPARATOR);
        } else {
            $path .= DIRECTORY_SEPARATOR;
            $path = rtrim($path, DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR);
        }
        if (strval(trim($path)) == '' or strval(trim($path)) == '/') {
            $path = $path_original;
        }
        if ($slash_it == false) {
        } else {
            $path = $path . DIRECTORY_SEPARATOR;
            $path = $this->reduce_double_slashes($path);
        }


        return $path;
    }


    function reduce_double_slashes($str)
    {
        return preg_replace("#([^:])//+#", "\\1/", $str);
    }


}
