<?php
/**
 * Extensions class for managing modules, addons, and plugins.
 *
 * @package Arraytics\ToolsSdk
 */
namespace Arraytics\ToolsSdk;

/**
 * Class Extension
 *
 * Handles registration and retrieval of plugin-related extensions such as modules, plugins, and addons.
 */
class Extension {
    /**
     * The option name used to store extension statuses in WordPress options table.
     *
     * @var string
     */
    protected $option_name = 'extensions';

    /**
     * List of registered extensions.
     *
     * @var array
     */
    protected $extensions = [];

    /**
     * Boot the extension system with provided option name and extensions list.
     *
     * @param string $option_name The option key used to save extension settings.
     * @param array  $extensions  List of available extensions.
     */
    public function __construct($option_name, $extensions) {
        $this->option_name = $option_name;
        $this->extensions = apply_filters('plugin_utility_manager/extensions', $extensions);
    }

    /**
     * Get all registered extensions with resolved status (on, off, upgrade, install, activate).
     *
     * @return array
     */
    public function get() {
        $settings = get_option($this->option_name, []);
        $resolved = [];

        foreach ($this->extensions as $key => $extension) {
            $status = isset($settings[$key]) ? $settings[$key] : $extension['status'];
            $extension['status'] = $status;

            $parent_key = isset($extension['parent']) ? $extension['parent'] : '';

            if ( ! empty( $parent_key ) ) {
                $parent_status = isset($settings[$parent_key]) ? $settings[$parent_key] : $this->extensions[$parent_key]['status'];
                
                $extension['status'] = $status === 'on' && $parent_status === 'on' ? 'on' : 'off';
            }

            $slug = isset($extension['slug']) ? $extension['slug'] : '';
            $deps = isset($extension['deps']) ? $extension['deps'] : [];

            if ($extension['type'] === 'module' && !empty($deps)) {
                $dep = $deps[0];

                if (!PluginManager::is_installed($dep)) {
                    $extension['status'] = 'upgrade';
                } elseif (!PluginManager::is_activated($dep)) {
                    $extension['status'] = 'install';
                } else {
                    $extension['status'] = 'activate';
                }
            } elseif (in_array($extension['type'], ['plugin', 'addon'])) {
                if (!PluginManager::is_installed($slug)) {
                    $extension['status'] = 'install';
                } elseif (!PluginManager::is_activated($slug)) {
                    $extension['status'] = 'activate';
                } else {
                    $extension['status'] = 'deactivate';
                }
            } elseif ($extension['type'] === 'arraytics-plugin') {
                if (!PluginManager::is_activated($slug)) {
                    $extension['status'] = 'install';
                } else {
                    $extension['status'] = 'activate';
                }
            }

            $resolved[$key] = $extension;
        }

        return $resolved;
    }

    /**
     * Update the status of a given extension.
     *
     * @param string $key    The extension key.
     * @param string $status The status to set ('on' or 'off').
     * @return bool True if update successful, false otherwise.
     */
    public function update($key, $status) {
        if (!isset($this->extensions[$key])) {
            return false;
        }

        $settings = get_option($this->option_name, []);
        $settings[$key] = $status === 'on' ? 'on' : 'off';

        return update_option($this->option_name, $settings);
    }

    /**
     * Get all extensions that are currently enabled (status = 'on').
     *
     * @return array
     */
    public function enabled() {
        return array_filter($this->get(), function ($ext) {
            return $ext['status'] === 'on';
        });
    }

    /**
     * Find an extension by its key.
     *
     * @param string $key The extension key.
     * @return array|null
     */
    public function find($key) {
        return isset($this->extensions[$key]) ? $this->extensions[$key] : null;
    }

    /**
     * Find the parent of a given extension.
     *
     * @param string $key The extension key.
     * @return array|null
     */
    public function find_parent($key) {
        return isset($this->extensions[$key]['parent']) ? $this->extensions[$key]['parent'] : null;
    }

    /**
     * Get the current option name used for storing extension settings.
     *
     * @return string
     */
    public function get_option_name() {
        return $this->option_name;
    }

    /**
     * Get all registered extensions as initially provided.
     *
     * @return array
     */
    public function get_extensions() {
        return $this->extensions;
    }

    /**
     * Get all extensions of type "plugin".
     *
     * @return array
     */
    public function get_plugins() {
        return array_filter($this->get(), function ($e) {
            return $e['type'] === 'plugin';
        });
    }

    /**
     * Get all extensions of type "addon".
     *
     * @return array
     */
    public function get_addons() {
        return array_filter($this->get(), function ($e) {
            return $e['type'] === 'addon';
        });
    }

    /**
     * Get all extensions of type "module".
     *
     * @return array
     */
    public function get_modules() {
        return array_filter($this->get(), function ($e) {
            return $e['type'] === 'module';
        });
    }

    /**
     * Get all extensions of type "arraytics-plugin".
     *
     * @return array
     */
    public function get_our_plugins() {
        return array_filter($this->get(), function ($e) {
            return $e['type'] === 'arraytics-plugin';
        });
    }

    /**
     * Get all submodules of a given module.
     *
     * @param string $module
     * @return array
     */
    public function get_submodule($module) {
        return array_filter($this->get(), function ($extension) use ($module) {
            return isset($extension['parent']) && $extension['parent'] === $module;
        });
    }

    /**
     * Check whether a module or submodule is enabled.
     *
     * @param string $key The extension key (module or submodule).
     * @return bool
     */
    public function is_enabled($key) {
        $extensions = $this->get();

        if (!isset($extensions[$key])) {
            return false;
        }

        $extension = $extensions[$key];
        $status = isset($extension['status']) ? $extension['status'] : 'off';

        if (empty($extension['parent'])) {
            return $status === 'on';
        }

        $parent_key = $extension['parent'];
        $parent_status = isset($extensions[$parent_key]['status']) ? $extensions[$parent_key]['status'] : 'off';

        return $status === 'on' && $parent_status === 'on';
    }
}
