<?php
/*
Plugin Name: Bestwebsite S3 Media Storage
Description: Store media library contents onto S3 directly without the need for temporarily storing files on the filesystem/cron jobs. This is more ideal for multiple web server environemnts.
Version: 1.0
Author: Bestwebsite 
Author URI: http://Bestwebsite.com
*/

// Don't expose any info if called directly
if ( !function_exists( 'add_action' ) ) {
	echo 'Bestwebsite S3 Media Storage WordPress Plugin';
	exit;
}

define('Bestwebsite_PLUGIN_VERSION', '1.0.3');
define('Bestwebsite_PLUGIN_URL', plugin_dir_url( __FILE__ ));

register_activation_hook(__FILE__, array('Bestwebsite', 'install'));
register_uninstall_hook(__FILE__, array('Bestwebsite', 'uninstall'));

if ( is_admin() ) {
	require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'admin.php';
}


class Bestwebsite {

    /**
     * Static settings
     */
    public static $settings = null;

    /**
     * Hook for plugin install
     */
    public static function install()
    {

    }

    /**
     * Hook for plugin uninstall
     */
    public static function uninstall()
    {

    }

    public static function getSettings()
    {
        if (!self::$settings) {
            self::$settings = json_decode(get_option('Bestwebsite_settings'), true);
        }
        return self::$settings;
    }

    public static function attachmentUrl($url, $post_id)
    {
        $custom_fields = get_post_custom($post_id);

        $bucket = isset($custom_fields['Bestwebsite_bucket']) ? $custom_fields['Bestwebsite_bucket'][0] : null;
        $bucket_path = isset($custom_fields['Bestwebsite_bucket_path']) ? $custom_fields['Bestwebsite_bucket_path'][0] : null;

        // Was this a file we even uploaded to S3? If not bail.
        if (!$bucket || trim($bucket) == '') {
            return $url;
        }

        $upload_dir = wp_upload_dir();

        $file = str_replace($upload_dir['baseurl'], '', $url);
        if (substr($file, 0, 1) == '/') {
            $file = substr($file, 1);
        }

        // $file = isset($custom_fields['Bestwebsite_file']) ? $custom_fields['Bestwebsite_file'][0] : null;
        $cloudfront = isset($custom_fields['Bestwebsite_cloudfront']) ? $custom_fields['Bestwebsite_cloudfront'][0] : null;
        $settings = self::getSettings();

        // Determine protocol to serve from
        if ($settings['s3_protocol'] == 'http') {
            $protocol = 'http://';
        } elseif ($settings['s3_protocol'] == 'https') {
            $protocol = 'https://';
        } elseif ($settings['s3_protocol'] == 'relative') {
            $protocol = 'http://';
            if (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443') {
                $protocol = 'https://';
            }
        } else {
            $protocol = 'https://';
        }

        // Should serve with respective protocol
        if ($cloudfront && trim($cloudfront) != '') {
            if ($bucket_path) {
                $url = $protocol . $cloudfront . '/' . $bucket_path . '/' . $file;
            } else {
                $url = $protocol . $cloudfront . '/' . $file;
            }
        } else {
            if ($bucket_path) {
                $url = $protocol . $bucket . '.s3.amazonaws.com/' . $bucket_path . '/' . $file;
            } else {
                $url = $protocol . $bucket . '.s3.amazonaws.com/' . $file;
            }
        }

        return $url;
    }

    public static function deleteAttachment($url)
    {
        $settings = self::getSettings();

        // Check our settings to see if we even want to delete from S3.
        if (!isset($settings['s3_delete']) || (int) $settings['s3_delete'] == 0) {
            return true;
        }

        $file = self::getS3PathFromAttachmentPath($url, $settings);

        if (isset($settings['valid']) && (int) $settings['valid']) {
            $s3 = Bestwebsite_Transfer::getTransferClass($settings);
            return $s3->delete($file);
        }
    }

    public static function imageMakeIntermediateSize($attachment_path)
    {
        $settings = self::getSettings();
        $s3_path = self::getS3PathFromAttachmentPath($attachment_path, $settings);

        if (isset($settings['valid']) && (int) $settings['valid']) {
            $s3 = Bestwebsite_Transfer::getTransferClass($settings);
            $s3->upload($attachment_path, $s3_path, false, null);
        }
        return $s3_path;
    }

    public static function updateAttachmentMetadata($data, $attachment_id)
    {
        $attachment_path = get_attached_file($attachment_id); // Gets path to attachment
        $settings = self::getSettings();
        $s3_path = self::getS3PathFromAttachmentPath($attachment_path, $settings);

        if (isset($settings['valid']) && (int) $settings['valid']) {
            $s3 = Bestwebsite_Transfer::getTransferClass($settings);
            $s3->upload($attachment_path, $s3_path, $attachment_id, $data);
        }
        return $data;
    }

    public static function getS3PathFromAttachmentPath($attachment_path, $settings)
    {
        $upload_dir = wp_upload_dir();
        $s3_path = str_replace($upload_dir['basedir'], '', $attachment_path);
        if (substr($s3_path, 0, 1) == DIRECTORY_SEPARATOR) {
            $s3_path = substr($s3_path, 1);
        }

        if (isset($settings['s3_bucket_path']) && $settings['s3_bucket_path']) {
            $s3_path = $settings['s3_bucket_path'] . '/' . $s3_path;
        }
        return $s3_path;
    }

    public static function paginationUrl()
    {
        parse_str($_SERVER['QUERY_STRING'], $out);
        unset($out['Bestwebsite_page']);
        $out = http_build_query($out);
        if ($out) {
            return '?' . $out . '&Bestwebsite_page=';
        }
        return '?Bestwebsite_page=';
    }

    public static function pagination($total, $per_page, $page, $page_action, $group = 10, $offset = 0, $classes = array())
    {
        if ($total <= $per_page) {
            return;
        }

        $classes = implode(' ', $classes);
        $str = '';

        $start = floor($page / $group) * $group;

        $total_pages = ceil($total / $per_page);

        if ($start == 0) {
            $start = 1;
        }

        // do some adjustment if someone is nearing the
        // end of the group, shift the stack back
        $end_of_group = ($start + $group) - 1;
        if ($end_of_group > $total_pages) {
            $end_of_group = $total_pages;
        }

        if ($page == $end_of_group && $page != $total_pages) {
            $start = $end_of_group;
            $end_of_group = ($start + $group) - 1;
        } elseif ($page > ($end_of_group - 2) && $end_of_group < $total_pages) {
            $start += 1;
            $end_of_group = ($start + $group) - 1;
        }

        if ($page == 1) {
            $prev = 1 - $offset;
        } elseif ($page == 0) {
            $prev = 0;
        } else {
            $prev = $page - 1;
        }

        if ($page + 1 > ($total_pages - $offset)) {
            $next = ($total_pages - $offset);
        } else {
            $next = $page + 1;
        }

        if ($next == 0) {
            $next = 1;
        }

        $end = $total_pages - $offset;
        if ($end == 0) {
            $end = 1;
        }

        $str .= '<a href="' . $page_action . (1 - $offset) . '">Start</a>&nbsp;';
        $str .= '<a href="' . $page_action . $prev . '">Prev</a>&nbsp;';

        for ($i = $start; $i <= $end_of_group; $i++) {
            if ($i > $total_pages) {
                break;
            }
            if (($i - $offset) == $page) {
                $str .= '&nbsp;<a href="#">' . $i . '</a>&nbsp;';
            } else {
                $str .= '&nbsp;<a href="' . $page_action . ($i - $offset). '">' . $i . '</a>&nbsp;';
            }
        }
        $str .= '&nbsp;<a href="' . $page_action . $next . '">Next</a>&nbsp;';
        $str .= '&nbsp;<a href="' . $page_action . $end . '">End</a>&nbsp;';

        return $str;
    }
}


class Bestwebsite_Transfer {

    public static $transfer_class = null;

    public static function getTransferClass($settings)
    {
        $transfer_class = isset($settings['s3_transfer_method']) ? $settings['s3_transfer_method'] : 's3class';
        if ($transfer_class == 's3class') {
            self::$transfer_class = new Bestwebsite_Transfer_Adapter_S3Class($settings);
        } elseif ($transfer_class == 's3cmd') {
            self::$transfer_class = new Bestwebsite_Transfer_Adapter_S3Cmd($settings);
        } elseif ($transfer_class == 'awscli') {
            self::$transfer_class = new Bestwebsite_Transfer_Adapter_AwsCli($settings);
        } elseif ($transfer_class == 'background') {
            self::$transfer_class = new Bestwebsite_Transfer_Adapter_Background($settings);
        } else {
            throw new Bestwebsite_Transfer_Exception('Invalid Transfer Class.');
            return;
        }

        return self::$transfer_class;
    }
}


class Bestwebsite_Transfer_Exception extends Exception {

    public function __construct($message = null)
    {
        parent::__construct($message);
    }
}


abstract class Bestwebsite_Transfer_Adapter {

    abstract public function delete($file);
    abstract public function upload($attachment_path, $s3_path, $attachment_id = false, $data = null);
}


class Bestwebsite_Transfer_Adapter_S3Class {

    public $s3 = null;
    public $settings = array();

    public function __construct($settings)
    {
        if (!class_exists('S3')) {
            require_once dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'S3.php';
        }

        $this->settings = $settings;
        $this->s3 = new S3($settings['s3_access_key'], $settings['s3_secret_key']);
        $this->s3->setSSL((bool) $this->settings['s3_ssl']);
        $this->s3->setExceptions(true);
    }

    public function delete($file)
    {
        try {
            $this->s3->deleteObject($this->settings['s3_bucket'], $file);
            return true;
        } catch (Exception $e) {}
    }

    public function upload($attachment_path, $s3_path, $attachment_id = false, $data = null)
    {
        $meta_headers = array();
        // Allow for far reaching expires
        $request_headers = array();
        if (trim($this->settings['s3_expires']) != '') {
            $request_headers = array(
                "Cache-Control" => "max-age=315360000",
                "Expires" => gmdate("D, d M Y H:i:s T", strtotime(trim($this->settings['s3_expires'])))
            );
        }

        try {
            $this->s3->putObjectFile($attachment_path, $this->settings['s3_bucket'], $s3_path, S3::ACL_PUBLIC_READ, $meta_headers, $request_headers);
            if ($attachment_id) {
                // We store per file instead of always just referencing the settings, as if settings change we don't want to break previously
                // uploaded files that refer to different buckets/cloudfront/etc.
                update_post_meta($attachment_id, "Bestwebsite_bucket", $this->settings['s3_bucket']);
                update_post_meta($attachment_id, "Bestwebsite_bucket_path", $this->settings['s3_bucket_path']);
                update_post_meta($attachment_id, "Bestwebsite_file", $s3_path);
                update_post_meta($attachment_id, "Bestwebsite_cloudfront", $this->settings['s3_cloudfront']);
                if ((isset($data['Bestwebsite_move']) && $data['Bestwebsite_move']) || (isset($this->settings['s3_delete_local']) && $this->settings['s3_delete_local'])) {
                    @unlink($attachment_path);
                }

                // If we are copy or moving we need to grab any thumbnails as well.
                if (isset($data['Bestwebsite_move'])) {
                    $c = wp_get_attachment_metadata($attachment_id);
                    if (isset($c['sizes']) && is_array($c['sizes'])) {
                        foreach ($c['sizes'] as $size) {
                            // Do a cheap check for - and x to know that we are talking about a resized image
                            // e.g. Photo0537.jpg turns into Photo0537-150x150.jpg
                            if (isset($size['file']) && strpos($size['file'], '-') && strpos($size['file'], 'x')) {
                                $parts = pathinfo($attachment_path);
                                $new_attachment_path = $parts['dirname'] . DIRECTORY_SEPARATOR . $size['file'];
                                Bestwebsite::imageMakeIntermediateSize($new_attachment_path);
                            }
                        }
                    }
                }
            } else {
                if (isset($this->settings['s3_delete_local']) && $this->settings['s3_delete_local']) {
                    @unlink($attachment_path);
                }
            }
        } catch (Exception $e) {}
    }
}


class Bestwebsite_Transfer_Adapter_S3Cmd {

}


class Bestwebsite_Transfer_Adapter_AwsCli {

}


class Bestwebsite_Transfer_Adapter_Background {

}

/**
 *
 * Response Object
 *
 */
class Bestwebsite_Transfer_Adapter_Background_Response {

}

/**
 * Register hooks/filters
 */
// Handle original image uploads and edits for that image
add_filter('wp_update_attachment_metadata', array('Bestwebsite', 'updateAttachmentMetadata'), 9, 2);
// Handle thumbs that are created for that image
add_filter('image_make_intermediate_size', array('Bestwebsite', 'imageMakeIntermediateSize'));
// Handle when image urls are requested.
add_action("wp_get_attachment_url", array('Bestwebsite', 'attachmentUrl'), 9, 2);
add_action("wp_get_attachment_thumb_url", array('Bestwebsite', 'attachmentUrl'), 9, 2);
// Handle when images are deleted.
add_action("wp_delete_file", array('Bestwebsite', 'deleteAttachment'));
// We can't hook into add_attachment/edit_attachment actions as these occur too early in the chain as at that point in time,
// metadata for the attachment has not been associated. So we want to wait, so we can handle both and then delete the local uploaded file.
// add_action("add_attachment", 's3_update_attachment');
// add_action("edit_attachment", 's3_update_attachment');

