<?php
declare(strict_types=1);
namespace Coinsnap\Util;

if (!defined('ABSPATH')) {
    exit;
}

class Notice {
   
    public function addNotice(string $type, string $content, $dismissible = 1){
        $notice_types_array = ['error','warning','success','info'];
        if(!in_array($type,$notice_types_array,true)){ $type = 'info'; }
        if(!empty($content)){
            setcookie('coinsnap_notices['.hash('sha256',$content).']',wp_json_encode(['type' => $type,'message' => $content, 'dismissible' => $dismissible]),time()+5,'/');
        }
    }
    
    public function showNotices(){
        // The cookie is written by the browser: anything but an array of JSON strings is ignored (6.1).
        if(!isset($_COOKIE['coinsnap_notices']) || !is_array($_COOKIE['coinsnap_notices'])){
            return;
        }
        foreach(wp_unslash($_COOKIE['coinsnap_notices']) as $coinsnap_notice){
            if(!is_string($coinsnap_notice)){
                continue;
            }
            // JSON, never unserialize().
            $notice = json_decode(sanitize_text_field($coinsnap_notice), true);
            if (!is_array($notice) || !is_string($notice['type'] ?? null) || !is_string($notice['message'] ?? null)) {
                continue;
            }
            $dismissible_add = !empty($notice['dismissible']) ? ' is-dismissible' : '';
            echo '<div class="notice notice-'.esc_html($notice['type'].$dismissible_add).'"><p>'.esc_html($notice['message']).'</p></div>';
        }
    }
}
