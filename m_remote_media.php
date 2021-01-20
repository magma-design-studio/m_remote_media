<?php
/*
Plugin Name:  Remote Media
Plugin URI:   https://magmadesignstudio.de
Description:  This plugin loads uploads from a remote server (such as a production environment) on demand, so you do not necessarily have to load all the files of the uploads folder.
Version:      0.0.2
Author:       magma, Sebastian Tiede
Author URI:   https://magmadesignstudio.de
*/

defined( 'ABSPATH' ) or die( 'No script kiddies please!' );

$pkg_file = __DIR__ . '/package.json';
if(file_exists($pkg_file)) {
    $pkg = json_decode(file_get_contents($pkg_file));
} else {
    wp_die('m_remote_media: package.json is missing!');
}

define( 'MREMMED_PLUGIN_NAME_SLUG', $pkg->name );

define( 'MREMMED_VERSION', $pkg->version );
define( 'MREMMED__MINIMUM_WP_VERSION', '4.0' );
define( 'MREMMED__PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MREMMED__PLUGIN_DIR_URL', plugin_dir_url( __FILE__ ) );

class m_remote_media {
    function initialize() {  
        if(isset($_GET['action']) and $_GET['action'] == 'upgrade') {
            return;
        }
          
        add_action('generate_rewrite_rules', array($this, 'add_htaccess_rules'));
        add_action('init', array($this, 'media_content'));
        
        add_action( 'admin_menu', array($this, 'admin_options' ));
        
        add_action( 'm_remote_media/load_attachment', array($this, '_action_load_attachment' ));
    }
    
    public static function set_version() {
        update_option('m_remote_media_version', MREMMED_VERSION, false);
    }
    
    function admin_options() {
        add_options_page( 
            'Remote Media Settings',
            'Remote Media',
            'manage_options',
            __FILE__, //'m_remote_media_settings',
            array($this, 'admin_options_page')
        );    
        
        add_action( 'admin_init', array($this, 'register_m_remote_media_settings') );
    }
    
    function register_m_remote_media_settings() {
        register_setting( 'm_remote_media_settings_basics', 'm_remote_media_remote_url' );
        register_setting( 'm_remote_media_settings_basics', 'm_remote_media_ignore_local' );
    }
    
    function admin_options_page() {    
        ?>
        <div class="wrap">
            <h1>Remote Media Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'm_remote_media_settings_basics' ); ?>
                <?php do_settings_sections( 'm_remote_media_settings_basics' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="m_remote_media_remote_url">Remote website address (URL)</label>
                        </th>
                        <td>
                            <input name="m_remote_media_remote_url" type="url" id="m_remote_media_remote_url" value="<?php echo esc_attr( get_option('m_remote_media_remote_url') ); ?>" class="regular-text code" placeholder="https://example.com" />
                            <p class="description">Entfernte Website-URL</p>
                        </td>
                    </tr> 
                    <tr>
                        <th scope="row">
                            <label for="m_remote_media_ignore_local">Ignore local files</label>
                        </th>
                        <td>
                            <input name="m_remote_media_ignore_local" type="checkbox" id="m_remote_media_ignore_local"<?php if(get_option('m_remote_media_ignore_local') == 'on') : ?> checked<?php endif; ?> />
                        </td>
                    </tr>                                    
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    public static function get_home_root() {
		$home_root = parse_url( home_url() );
		if ( isset( $home_root['path'] ) ) {
			return trailingslashit( $home_root['path'] );
		} else {
			return '/';
		}  
		
		return $home_root;       
    }
    
    public static function add_htaccess_rules() {
        if(!function_exists('extract_from_markers')) {
            return;
        }
    
        //save_mod_rewrite_rules();
        $home_path     = ABSPATH;
        $htaccess_file = $home_path . '.htaccess';       
        
		$home_root = self::get_home_root();     
        
        $insertion = "
            <IfModule mod_rewrite.c>
                RewriteEngine On
                RewriteBase {$home_root}
                RewriteRule ^wp-content/uploads/(.*)$ /index.php?m_remote_media=true
            </IfModule>
        ";
        
        if(extract_from_markers($htaccess_file, 'm_remote_media')) {
            //insert_with_markers( $htaccess_file, 'm_remote_media', $insertion );           
        } else {
            $insertion = sprintf(
                "
                # BEGIN m_remote_media
                %s
                # END m_remote_media
                ", 
                $insertion
            );
            $insertion .= file_get_contents($htaccess_file);
            
            file_put_contents($htaccess_file, $insertion);
        }
    
    }    
    
    public static function remove_htaccess_rules() {

        $home_path     = ABSPATH;
        $htaccess_file = $home_path . '.htaccess';    
                
        $htaccess_content = file_get_contents($htaccess_file);
        
        $htaccess_content = preg_replace('/# BEGIN m_remote_media(.*)# END m_remote_media/is', null, $htaccess_content);   
                
        file_put_contents($htaccess_file, $htaccess_content);
    }
    
    function convert_local_to_remote($local) {
        if(!($remote = esc_attr( get_option('m_remote_media_remote_url') ))) {
            return false;
        }
        $local = $this->convert_to_path($local);
        
        $remote = untrailingslashit($remote);
        $remote .= $local;    
    
        return $remote;
    }
    
    function convert_to_path($url) {
        $path = preg_replace(sprintf('/^%s/', preg_quote(untrailingslashit(ABSPATH), '/')), null, $url);
        $path = preg_replace(sprintf('/^%s/', preg_quote(untrailingslashit(get_bloginfo('url')), '/')), null, $path);
        if($remote = esc_attr( get_option('m_remote_media_remote_url') )) {
            $path = preg_replace(sprintf('/^%s/', preg_quote(untrailingslashit($remote), '/')), null, $path);
        }
        
        return $path;
    }
    
    function media_content() {
        if(empty($_GET['m_remote_media'])) {
            return;
        }
        
        $request = $_SERVER['REQUEST_URI'];
        $local = untrailingslashit(ABSPATH);
        $local .= $request;        

        if(!($remote = $this->convert_local_to_remote($local))) {
            return;
        }
        
		$home_root = self::get_home_root();     

        
        if(file_exists($local) and get_option('m_remote_media_ignore_local') != 'on') {     
            $mime = mime_content_type($local);
            $content = file_get_contents($local);
        } else {
            $upload_dir = wp_upload_dir(null, false);
        
            $cache_folder = sprintf('%s/_remote_media_cache', $upload_dir['basedir']);
            $cache_file = sprintf('%s/%s', $cache_folder, sha1($request));
        
            if(file_exists($cache_file)) {
                $file = unserialize(file_get_contents($cache_file));
                $mime = $file['mime'];
                $content = $file['content'];
            } else {
                @mkdir($cache_folder, 0750, true);

                if($file_array = $this->get_file($remote)) {
                    file_put_contents(sprintf('%s/%s', $cache_folder, sha1($request)), serialize($file_array));     
                    $mime = $file_array['content_type'];           
                    $content = $file_array['content'];           
                } else {
                    header("HTTP/1.0 404 Not Found");
                    die('Not found!');
                }
            }
        }
        
        header(sprintf('Content-Type: %s;', $mime));

        die($content);
    } 
    
    function get_file($remote) {
        $ch = curl_init($remote);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if(curl_exec($ch) === false) {
            return false;
        } else {
            $header  = curl_getinfo( $ch );

            return array(
                'mime' => $header['content_type'],
                'content' => curl_exec( $ch )
            );
        }    
    }
    
    function _action_load_attachment($local) {
        
        
        if(!($remote = $this->convert_local_to_remote($local))) {
            return false;
        }
        

        $path = $this->convert_to_path($local);
        
        $dir = dirname($path);
        $basename = basename($path);
        		      
        		      
        $local_dir = untrailingslashit(ABSPATH) . $dir;
        $local_file = sprintf('%s/%s', untrailingslashit($local_dir), $basename);
        
        
        if(file_exists($local_file)) {
            return true;
        }
           
                
        if(file_exists($local_dir) or @mkdir($local_dir)) {        
            if($file = $this->get_file($remote)) {
                file_put_contents($local_file, $file['content']);
                return true;
            }
        }
                
        return false;
    }

}

function m_remote_media() {
    global $m_remote_media;

    if( !isset($m_remote_media) ) {
        $m_remote_media = new m_remote_media();
        $m_remote_media->initialize();
    }

    return $m_remote_media;        
}

register_activation_hook( __FILE__, array('m_remote_media', 'set_version') );
register_activation_hook( __FILE__, array('m_remote_media', 'add_htaccess_rules') );
register_deactivation_hook( __FILE__, array('m_remote_media', 'remove_htaccess_rules') );

m_remote_media();