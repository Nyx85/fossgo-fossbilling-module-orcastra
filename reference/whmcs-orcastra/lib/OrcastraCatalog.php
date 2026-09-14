<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

function orcastra_split_list($raw)
{
    $parts = preg_split('/[\s,;]+/', trim((string) $raw));
    $out = array();
    foreach ($parts as $p) {
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

function orcastra_lxd_extract_metadata($raw)
{
    if (isset($raw['data']['metadata']) && is_array($raw['data']['metadata'])) {
        return $raw['data']['metadata'];
    }
    if (isset($raw['metadata']) && is_array($raw['metadata'])) {
        return $raw['metadata'];
    }
    if (isset($raw['data']) && is_array($raw['data'])) {
        return $raw['data'];
    }
    return array();
}

function orcastra_lxd_rows_are_urls($meta)
{
    if (!is_array($meta) || !$meta) {
        return false;
    }
    foreach ($meta as $row) {
        if (!is_string($row) || $row === '') {
            return false;
        }
    }
    return true;
}

function orcastra_lxd_fetch_url_rows($client, $cluster, $project, $urls)
{
    $out = array();
    foreach ($urls as $url) {
        if (!is_string($url) || $url === '') {
            continue;
        }
        $path = $url;
        if (preg_match('#https?://[^/]+(/.*)$#', $url, $m)) {
            $path = $m[1];
        }
        try {
            $one = $client->rawProxy($cluster, 'GET', $path, $project);
            $row = orcastra_lxd_extract_metadata($one);
            if ($row && array_keys($row) !== range(0, count($row) - 1)) {
                $out[] = $row;
            } elseif (is_array($row) && $row && !orcastra_lxd_rows_are_urls($row)) {
                foreach ($row as $item) {
                    if (is_array($item)) {
                        $out[] = $item;
                    }
                }
            }
        } catch (Throwable $e) {
            logModuleCall('orcastra', 'lxd_fetch_url', array('path' => $path), $e->getMessage(), null);
        }
    }
    return $out;
}

function orcastra_lxd_metadata($client, $cluster, $project, $path)
{
    $project = ($project === null || $project === '') ? 'default' : $project;
    $raw = $client->rawProxy($cluster, 'GET', $path, $project);
    $meta = orcastra_lxd_extract_metadata($raw);
    if (!orcastra_lxd_rows_are_urls($meta)) {
        return $meta;
    }
    if (strpos($path, 'recursion=1') === false) {
        $sep = (strpos($path, '?') === false) ? '?' : '&';
        try {
            $raw2 = $client->rawProxy($cluster, 'GET', $path . $sep . 'recursion=1', $project);
            $meta2 = orcastra_lxd_extract_metadata($raw2);
            if ($meta2 && !orcastra_lxd_rows_are_urls($meta2)) {
                return $meta2;
            }
            if ($meta2) {
                $meta = $meta2;
            }
        } catch (Throwable $e) {
            logModuleCall('orcastra', 'lxd_metadata_recursion', array('path' => $path), $e->getMessage(), null);
        }
    }
    if (orcastra_lxd_rows_are_urls($meta)) {
        return orcastra_lxd_fetch_url_rows($client, $cluster, $project, $meta);
    }
    return $meta;
}

function orcastra_remote_servers()
{
    return array(
        'images:' => 'https://images.lxd.canonical.com',
        'ubuntu:' => 'https://cloud-images.ubuntu.com/releases',
        'ubuntu-minimal:' => 'https://cloud-images.ubuntu.com/minimal/releases',
    );
}

function orcastra_http_json($url, $ttl = 21600)
{
    $dir = __DIR__ . '/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $cache = $dir . '/ss-' . md5($url) . '.json';
    if (is_file($cache) && filemtime($cache) > time() - $ttl) {
        $decoded = json_decode((string) file_get_contents($cache), true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => array('Accept: application/json'),
        CURLOPT_USERAGENT => 'orcastra-whmcs',
    ));
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno || $status >= 400) {
        throw new Exception('Simplestreams HTTP ' . $status . ' for ' . $url);
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new Exception('Simplestreams non-JSON for ' . $url);
    }
    @file_put_contents($cache, $raw);
    return $decoded;
}

function orcastra_simplestreams_index_products($baseUrl)
{
    $base = rtrim($baseUrl, '/');
    $index = orcastra_http_json($base . '/streams/v1/index.json');
    $path = '';
    $best = -1;
    foreach (isset($index['index']) ? $index['index'] : array() as $entry) {
        if (!is_array($entry) || empty($entry['path'])) {
            continue;
        }
        $p = (string) $entry['path'];
        if (strpos($p, 'aws') !== false || strpos($p, 'azure') !== false || strpos($p, 'gce') !== false) {
            continue;
        }
        $n = isset($entry['products']) && is_array($entry['products']) ? count($entry['products']) : 0;
        if (strpos($p, 'download') !== false || strpos($p, 'images.json') !== false) {
            $n += 1000;
        }
        if ($n > $best) {
            $best = $n;
            $path = $p;
        }
    }
    if ($path === '') {
        return array();
    }
    $url = (strpos($path, 'http') === 0) ? $path : ($base . '/' . ltrim($path, '/'));
    $data = orcastra_http_json($url);
    return isset($data['products']) && is_array($data['products']) ? $data['products'] : array();
}

function orcastra_catalog_parse_local_images($meta, $prefix = '[local] ')
{
    $opts = array();
    foreach ((array) $meta as $img) {
        if (!is_array($img)) {
            continue;
        }
        $aliases = array();
        if (isset($img['aliases']) && is_array($img['aliases'])) {
            foreach ($img['aliases'] as $al) {
                if (is_array($al) && !empty($al['name'])) {
                    $aliases[] = $al['name'];
                } elseif (is_string($al) && $al !== '') {
                    $aliases[] = $al;
                }
            }
        }
        $fp = isset($img['fingerprint']) ? substr((string) $img['fingerprint'], 0, 12) : '';
        $desc = '';
        if (isset($img['properties']['description'])) {
            $desc = $img['properties']['description'];
        } elseif (isset($img['description'])) {
            $desc = $img['description'];
        }
        if ($aliases) {
            foreach ($aliases as $alias) {
                $opts[$alias] = $prefix . $alias . ($desc ? ' — ' . $desc : '');
            }
        } elseif ($fp) {
            $opts[$fp] = $prefix . $fp . ($desc ? ' — ' . $desc : '');
        }
    }
    return $opts;
}

function orcastra_catalog_fetch_local_images($client, $cluster, $project)
{
    try {
        return orcastra_lxd_metadata($client, $cluster, $project, '/1.0/images?recursion=1');
    } catch (Throwable $e) {
        logModuleCall('orcastra', 'catalog_local_images', array('cluster' => $cluster, 'project' => $project), $e->getMessage(), null);
        return array();
    }
}

function orcastra_catalog_local_images($client, $cluster, $project)
{
    $project = trim((string) $project);
    if ($project === '') {
        $project = 'default';
    }
    $opts = orcastra_catalog_parse_local_images(
        orcastra_catalog_fetch_local_images($client, $cluster, $project),
        '[local] '
    );
    if (!$opts && strcasecmp($project, 'default') !== 0) {
        $opts = orcastra_catalog_parse_local_images(
            orcastra_catalog_fetch_local_images($client, $cluster, 'default'),
            '[local/default] '
        );
    }
    if (!$opts) {
        $opts = orcastra_catalog_parse_local_images(
            orcastra_catalog_fetch_local_images($client, $cluster, ''),
            '[local/default] '
        );
    }
    ksort($opts, SORT_NATURAL | SORT_FLAG_CASE);
    return $opts;
}

function orcastra_catalog_images($client, $cluster, $project, $includeLibrary = true)
{
    $opts = array();
    try {
        $opts = orcastra_catalog_parse_local_images(
            orcastra_lxd_metadata($client, $cluster, $project, '/1.0/images?recursion=1'),
            '[local] '
        );
    } catch (Throwable $e) {
        logModuleCall('orcastra', 'catalog_local_images', array('cluster' => $cluster), $e->getMessage(), null);
    }
    if (!$includeLibrary) {
        ksort($opts, SORT_NATURAL | SORT_FLAG_CASE);
        return $opts;
    }

    $slimFile = __DIR__ . '/cache/aliases-slim.json';
    if (is_file($slimFile)) {
        $slim = json_decode((string) file_get_contents($slimFile), true);
        if (is_array($slim)) {
            foreach (array('images', 'ubuntu') as $bucket) {
                if (!empty($slim[$bucket]) && is_array($slim[$bucket])) {
                    foreach ($slim[$bucket] as $k => $label) {
                        if (!isset($opts[$k])) {
                            $opts[$k] = $label;
                        }
                    }
                }
            }
            ksort($opts, SORT_NATURAL | SORT_FLAG_CASE);
            return $opts;
        }
    }

    try {
        foreach (orcastra_simplestreams_index_products('https://images.lxd.canonical.com') as $product) {
            if (!is_array($product)) {
                continue;
            }
            $arch = isset($product['arch']) ? $product['arch'] : '';
            if ($arch && $arch !== 'amd64' && $arch !== 'x86_64') {
                continue;
            }
            $aliases = orcastra_split_list(isset($product['aliases']) ? $product['aliases'] : '');
            $os = isset($product['os']) ? $product['os'] : '';
            $title = isset($product['release_title']) ? $product['release_title'] : (isset($product['release']) ? $product['release'] : '');
            $variant = isset($product['variant']) ? $product['variant'] : '';
            $labelExtra = trim($os . ' ' . $title . ($variant && $variant !== 'default' ? ' (' . $variant . ')' : ''));
            foreach ($aliases as $alias) {
                $key = 'images:' . $alias;
                if (!isset($opts[$key])) {
                    $opts[$key] = '[images] ' . $alias . ($labelExtra ? ' — ' . $labelExtra : '');
                }
            }
        }
    } catch (Throwable $e) {
        logModuleCall('orcastra', 'catalog_images_remote', array(), $e->getMessage(), null);
    }

    try {
        foreach (orcastra_simplestreams_index_products('https://cloud-images.ubuntu.com/releases') as $product) {
            if (!is_array($product)) {
                continue;
            }
            if (isset($product['supported']) && $product['supported'] === false) {
                continue;
            }
            $arch = isset($product['arch']) ? $product['arch'] : '';
            if ($arch && $arch !== 'amd64' && $arch !== 'x86_64') {
                continue;
            }
            $version = isset($product['version']) ? (string) $product['version'] : '';
            if ($version === '' || !preg_match('/^\d+\.\d+/', $version)) {
                continue;
            }
            $title = isset($product['release_title']) ? $product['release_title'] : $version;
            $opts['ubuntu:' . $version] = '[ubuntu] ' . $version . ' — Ubuntu ' . $title;
        }
    } catch (Throwable $e) {
        logModuleCall('orcastra', 'catalog_ubuntu_remote', array(), $e->getMessage(), null);
    }

    ksort($opts, SORT_NATURAL | SORT_FLAG_CASE);
    return $opts;
}


function orcastra_configoption_value(array $params, $names, $fallback)
{
    if (!empty($params['configoptions']) && is_array($params['configoptions'])) {
        foreach ((array) $names as $n) {
            if (isset($params['configoptions'][$n]) && $params['configoptions'][$n] !== '') {
                $val = (string) $params['configoptions'][$n];
                if (strpos($val, '|') !== false) {
                    $val = explode('|', $val, 2)[0];
                }
                return trim($val);
            }
        }
    }
    return $fallback;
}

function orcastra_chosen_cpu(array $params)
{
    $v = orcastra_configoption_value($params, array('vCPU (2 Ghz)', 'vCPU', 'CPU'), orcastra_opt($params, 5, '2'));
    $n = preg_replace('/[^0-9]/', '', (string) $v);
    return $n !== '' ? $n : '2';
}

function orcastra_chosen_memory(array $params)
{
    $v = orcastra_configoption_value($params, array('RAM (GB)', 'Memory', 'RAM'), orcastra_opt($params, 6, '2GB'));
    if (preg_match('/^\d+$/', (string) $v)) {
        return $v . 'GB';
    }
    return (string) $v;
}

function orcastra_chosen_disk(array $params)
{
    $v = orcastra_configoption_value($params, array('System Storage (GB)', 'Storage', 'Disk'), orcastra_opt($params, 7, '20GB'));
    if (preg_match('/^\d+$/', (string) $v)) {
        return $v . 'GB';
    }
    return (string) $v;
}

function orcastra_image_family($key, $label)
{
    $s = strtolower($key . ' ' . $label);
    $map = array(
        'ubuntu' => 'Ubuntu',
        'debian' => 'Debian',
        'alma' => 'AlmaLinux',
        'centos' => 'CentOS',
        'rocky' => 'Rocky Linux',
        'alpine' => 'Alpine',
        'fedora' => 'Fedora',
        'opensuse' => 'openSUSE',
        'oracle' => 'Oracle Linux',
        'archlinux' => 'Arch Linux',
        'arch/' => 'Arch Linux',
        'kali' => 'Kali',
        'mint' => 'Linux Mint',
        'windows' => 'Windows',
        'amazon' => 'Amazon Linux',
        'devuan' => 'Devuan',
        'gentoo' => 'Gentoo',
        'openwrt' => 'OpenWrt',
        'openeuler' => 'openEuler',
        'slackware' => 'Slackware',
        'voidlinux' => 'Void Linux',
        'busybox' => 'BusyBox',
    );
    foreach ($map as $needle => $fam) {
        if (strpos($s, $needle) !== false) {
            return $fam;
        }
    }
    return 'Custom OS';
}


function orcastra_family_logo_slug($family)
{
    $map = array(
        'Ubuntu' => 'ubuntu',
        'Debian' => 'debian',
        'AlmaLinux' => 'almalinux',
        'CentOS' => 'centos',
        'Rocky Linux' => 'rockylinux',
        'Alpine' => 'alpine',
        'Fedora' => 'fedora',
        'openSUSE' => 'opensuse',
        'Oracle Linux' => 'oracle',
        'Arch Linux' => 'archlinux',
        'Kali' => 'kalilinux',
        'Linux Mint' => 'linuxmint',
        'Windows' => 'windows',
        'Amazon Linux' => 'amazon',
        'Devuan' => 'devuan',
        'Gentoo' => 'gentoo',
        'OpenWrt' => 'openwrt',
        'openEuler' => 'openeuler',
        'Slackware' => 'slackware',
        'Void Linux' => 'voidlinux',
        'BusyBox' => 'busybox',
        'Custom OS' => 'orca',
    );
    $family = (string) $family;
    return isset($map[$family]) ? $map[$family] : 'linux';
}

function orcastra_image_version_label($key, $label)
{
    $key = (string) $key;
    if (preg_match('/ubuntu:(\d+\.\d+)/', $key, $m)) {
        return $m[1] . ' LTS';
    }
    $rest = $key;
    if (preg_match('/^(images|ubuntu|ubuntu-minimal):(.+)$/', $key, $m)) {
        $rest = $m[2];
    }
    $parts = explode('/', $rest);
    if (count($parts) >= 2) {
        array_shift($parts);
        $ver = array_shift($parts);
        $variant = implode('/', $parts);
        if ($variant === '' || strtolower($variant) === 'default') {
            return $ver;
        }
        return $ver . ' (' . $variant . ')';
    }
    if (preg_match('#debian/(\d+)#', $key, $m)) {
        return $m[1];
    }
    if (preg_match('#/(\d+\.\d+)#', $key, $m)) {
        return $m[1];
    }
    $label = preg_replace('/^\[(local|images|ubuntu)\]\s*/', '', (string) $label);
    return $label !== '' ? $label : $key;
}

function orcastra_yesno_on($raw)
{
    $v = strtolower(trim((string) $raw));
    return in_array($v, array('on', 'yes', 'true', '1'), true);
}

function orcastra_os_option_id($pid)
{
    $pid = (int) $pid;
    if ($pid < 1) {
        return 0;
    }
    $rows = Capsule::table('tblproductconfigoptions')
        ->join('tblproductconfiglinks', 'tblproductconfiglinks.gid', '=', 'tblproductconfigoptions.gid')
        ->where('tblproductconfiglinks.pid', $pid)
        ->where('tblproductconfigoptions.hidden', 0)
        ->select('tblproductconfigoptions.id', 'tblproductconfigoptions.optionname', 'tblproductconfigoptions.optiontype', 'tblproductconfigoptions.order')
        ->orderBy('tblproductconfigoptions.order')
        ->orderBy('tblproductconfigoptions.id')
        ->get();
    $fallback = 0;
    foreach ($rows as $row) {
        $name = strtolower(trim((string) $row->optionname));
        $type = (int) $row->optiontype;
        if ($name === 'operating system' || $name === 'image' || $name === 'os') {
            return (int) $row->id;
        }
        if ($fallback < 1 && $type === 1 && (strpos($name, 'operating') !== false || $name === 'image' || preg_match('/\bos\b/', $name))) {
            $fallback = (int) $row->id;
        }
    }
    return $fallback;
}

function orcastra_alias_is_custom($alias, $family)
{
    $alias = trim((string) $alias);
    if ($family === 'Custom OS') {
        return true;
    }
    if ($alias !== '' && preg_match('/^[0-9a-f]{12,}$/i', $alias)) {
        return true;
    }
    return false;
}

function orcastra_order_os_items($pid, $osid = 0)
{
    $pid = (int) $pid;
    $osid = (int) $osid;
    if ($osid < 1) {
        $osid = orcastra_os_option_id($pid);
    }
    if ($osid < 1) {
        return array();
    }
    $subs = Capsule::table('tblproductconfigoptionssub')
        ->where('configid', $osid)
        ->where('hidden', 0)
        ->orderBy('sortorder')
        ->orderBy('id')
        ->get();
    $items = array();
    foreach ($subs as $sub) {
        $raw = (string) $sub->optionname;
        $alias = $raw;
        $label = $raw;
        if (strpos($raw, '|') !== false) {
            $parts = explode('|', $raw, 2);
            $alias = trim($parts[0]);
            $label = trim($parts[1]) !== '' ? trim($parts[1]) : $alias;
        }
        $fam = orcastra_image_family($alias, $label);
        $custom = orcastra_alias_is_custom($alias, $fam);
        $items[] = array(
            'value' => (string) $sub->id,
            'alias' => $alias,
            'label' => $label !== '' ? $label : $alias,
            'family' => $fam,
            'logo' => orcastra_family_logo_slug($custom ? 'Custom OS' : $fam),
            'version' => orcastra_image_version_label($alias, $label),
            'local' => true,
            'custom' => $custom,
        );
    }
    return $items;
}

function orcastra_pricing_zero($relid, $monthly = 0)
{
    $currencies = Capsule::table('tblcurrencies')->pluck('id');
    foreach ($currencies as $cid) {
        $exists = Capsule::table('tblpricing')->where('type', 'configoptions')->where('currency', $cid)->where('relid', $relid)->first();
        $row = array(
            'type' => 'configoptions',
            'currency' => $cid,
            'relid' => $relid,
            'msetupfee' => 0,
            'qsetupfee' => 0,
            'ssetupfee' => 0,
            'asetupfee' => 0,
            'bsetupfee' => 0,
            'tsetupfee' => 0,
            'monthly' => $monthly,
            'quarterly' => 0,
            'semiannually' => 0,
            'annually' => 0,
            'biennially' => 0,
            'triennially' => 0,
        );
        if ($exists) {
            Capsule::table('tblpricing')->where('id', $exists->id)->update(array('monthly' => $monthly));
        } else {
            Capsule::table('tblpricing')->insert($row);
        }
    }
}

function orcastra_ensure_order_options($pid)
{
    $pid = (int) $pid;
    if ($pid < 1) {
        return 0;
    }
    $groupName = 'Orcastra VM';
    $link = Capsule::table('tblproductconfiglinks')
        ->join('tblproductconfiggroups', 'tblproductconfiggroups.id', '=', 'tblproductconfiglinks.gid')
        ->where('tblproductconfiglinks.pid', $pid)
        ->where('tblproductconfiggroups.name', $groupName)
        ->select('tblproductconfiggroups.id')
        ->first();
    if ($link) {
        $gid = (int) $link->id;
    } else {
        $gid = (int) Capsule::table('tblproductconfiggroups')->insertGetId(array(
            'name' => $groupName,
            'description' => 'Orcastra order options',
        ));
        Capsule::table('tblproductconfiglinks')->insert(array('gid' => $gid, 'pid' => $pid));
    }

    $sliders = array(
        array('vCPU (2 Ghz)', 1, 16, 37500, 1),
        array('RAM (GB)', 1, 32, 37500, 2),
        array('System Storage (GB)', 10, 1000, 1500, 3),
    );
    foreach ($sliders as $spec) {
        list($name, $min, $max, $price, $order) = $spec;
        $opt = Capsule::table('tblproductconfigoptions')->where('gid', $gid)->where('optionname', $name)->first();
        if ($opt) {
            $oid = (int) $opt->id;
            Capsule::table('tblproductconfigoptions')->where('id', $oid)->update(array(
                'optiontype' => 4,
                'qtyminimum' => $min,
                'qtymaximum' => $max,
                'hidden' => 0,
            ));
        } else {
            $oid = (int) Capsule::table('tblproductconfigoptions')->insertGetId(array(
                'gid' => $gid,
                'optionname' => $name,
                'optiontype' => 4,
                'qtyminimum' => $min,
                'qtymaximum' => $max,
                'order' => $order,
                'hidden' => 0,
            ));
        }
        $sub = Capsule::table('tblproductconfigoptionssub')->where('configid', $oid)->first();
        if ($sub) {
            $sid = (int) $sub->id;
        } else {
            $sid = (int) Capsule::table('tblproductconfigoptionssub')->insertGetId(array(
                'configid' => $oid,
                'optionname' => '1',
                'sortorder' => 0,
                'hidden' => 0,
            ));
        }
        orcastra_pricing_zero($sid, $price);
    }

    $os = Capsule::table('tblproductconfigoptions')->where('gid', $gid)->where('optionname', 'Operating System')->first();
    if ($os) {
        $osid = (int) $os->id;
    } else {
        $osid = (int) Capsule::table('tblproductconfigoptions')->insertGetId(array(
            'gid' => $gid,
            'optionname' => 'Operating System',
            'optiontype' => 1,
            'qtyminimum' => 0,
            'qtymaximum' => 0,
            'order' => 0,
            'hidden' => 0,
        ));
    }
    return $osid;
}

function orcastra_order_image_list($pid)
{
    $pid = (int) $pid;
    $row = Capsule::table('tblproducts')->where('id', $pid)->first();
    if (!$row) {
        return array();
    }
    $allowed = orcastra_split_list($row->configoption4);
    $includeLibrary = orcastra_yesno_on(isset($row->configoption12) ? $row->configoption12 : '');
    $items = array();
    $seen = array();
    foreach ($allowed as $alias) {
        $fam = orcastra_image_family($alias, $alias);
        $items[] = array(
            'value' => $alias,
            'label' => $alias,
            'family' => $fam,
            'version' => orcastra_image_version_label($alias, $alias),
            'local' => true,
        );
        $seen[$alias] = true;
    }
    if ($includeLibrary) {
        $slimFile = __DIR__ . '/cache/aliases-slim.json';
        if (is_file($slimFile)) {
            $slim = json_decode((string) file_get_contents($slimFile), true);
            if (is_array($slim)) {
                foreach (array('ubuntu', 'images') as $bucket) {
                    if (empty($slim[$bucket]) || !is_array($slim[$bucket])) {
                        continue;
                    }
                    foreach ($slim[$bucket] as $k => $label) {
                        if (isset($seen[$k])) {
                            continue;
                        }
                        $seen[$k] = true;
                        $items[] = array(
                            'value' => $k,
                            'label' => $label,
                            'family' => orcastra_image_family($k, $label),
                            'version' => orcastra_image_version_label($k, $label),
                            'local' => false,
                        );
                    }
                }
            }
        }
    }
    return $items;
}

function orcastra_image_source($image)
{
    $image = trim((string) $image);
    foreach (orcastra_remote_servers() as $prefix => $server) {
        if (strpos($image, $prefix) === 0) {
            return array(
                'type' => 'image',
                'mode' => 'pull',
                'protocol' => 'simplestreams',
                'server' => $server,
                'alias' => substr($image, strlen($prefix)),
            );
        }
    }
    if (preg_match('/^[0-9a-f]{12,}$/i', $image)) {
        return array('type' => 'image', 'fingerprint' => $image);
    }
    if (preg_match('#^ubuntu/(\d+\.\d+)#', $image, $m)) {
        return array(
            'type' => 'image',
            'mode' => 'pull',
            'protocol' => 'simplestreams',
            'server' => 'https://cloud-images.ubuntu.com/releases',
            'alias' => $m[1],
        );
    }
    if (strpos($image, '/') !== false) {
        return array(
            'type' => 'image',
            'mode' => 'pull',
            'protocol' => 'simplestreams',
            'server' => 'https://images.lxd.canonical.com',
            'alias' => $image,
        );
    }
    return array('type' => 'image', 'alias' => $image);
}

function orcastra_chosen_image(array $params)
{
    if (!empty($params['configoptions']) && is_array($params['configoptions'])) {
        foreach (array('Image', 'Operating System', 'OS') as $k) {
            if (!empty($params['configoptions'][$k])) {
                $val = (string) $params['configoptions'][$k];
                if (strpos($val, '|') !== false) {
                    $val = explode('|', $val, 2)[0];
                }
                return trim($val);
            }
        }
    }
    $parts = orcastra_split_list(orcastra_opt($params, 4, ''));
    if (count($parts) === 1) {
        return $parts[0];
    }
    if ($parts) {
        throw new Exception('Customer must choose an Image');
    }
    return 'ubuntu:24.04';
}

function orcastra_sync_image_configoptions($pid, $allowedRaw, $includeLibrary = false)
{
    $pid = (int) $pid;
    if ($pid < 1) {
        return;
    }
    $osid = orcastra_ensure_order_options($pid);
    $allowed = orcastra_split_list($allowedRaw);
    $labelMap = array();
    if ($includeLibrary) {
        $slimFile = __DIR__ . '/cache/aliases-slim.json';
        if (is_file($slimFile)) {
            $slim = json_decode((string) file_get_contents($slimFile), true);
            if (is_array($slim)) {
                foreach (array('ubuntu', 'images') as $bucket) {
                    if (!empty($slim[$bucket]) && is_array($slim[$bucket])) {
                        foreach ($slim[$bucket] as $k => $label) {
                            $k = trim((string) $k);
                            if ($k === '') {
                                continue;
                            }
                            $labelMap[$k] = (string) $label;
                            if (!in_array($k, $allowed, true)) {
                                $allowed[] = $k;
                            }
                        }
                    }
                }
            }
        }
    }
    if (!$allowed) {
        $allowed = array('ubuntu:24.04');
    }

    $groupName = 'Orcastra VM';
    $optionName = 'Operating System';
    $link = Capsule::table('tblproductconfiglinks')
        ->join('tblproductconfiggroups', 'tblproductconfiggroups.id', '=', 'tblproductconfiglinks.gid')
        ->where('tblproductconfiglinks.pid', $pid)
        ->where('tblproductconfiggroups.name', $groupName)
        ->select('tblproductconfiggroups.id')
        ->first();
    if ($link) {
        $gid = (int) $link->id;
    } else {
        $gid = (int) Capsule::table('tblproductconfiggroups')->insertGetId(array(
            'name' => $groupName,
            'description' => 'Orcastra instance options',
        ));
        Capsule::table('tblproductconfiglinks')->insert(array('gid' => $gid, 'pid' => $pid));
    }

    $opt = Capsule::table('tblproductconfigoptions')->where('gid', $gid)->where('optionname', $optionName)->first();
    if ($opt) {
        $oid = (int) $opt->id;
    } else {
        $oid = (int) Capsule::table('tblproductconfigoptions')->insertGetId(array(
            'gid' => $gid,
            'optionname' => $optionName,
            'optiontype' => 1,
            'qtyminimum' => 0,
            'qtymaximum' => 0,
            'order' => 1,
            'hidden' => 0,
        ));
    }

    $existing = Capsule::table('tblproductconfigoptionssub')->where('configid', $oid)->get();
    $byValue = array();
    foreach ($existing as $row) {
        $name = (string) $row->optionname;
        $value = strpos($name, '|') !== false ? explode('|', $name, 2)[0] : $name;
        $byValue[$value] = $row;
    }

    $currencies = Capsule::table('tblcurrencies')->pluck('id');
    $sort = 0;
    $seenAlias = array();
    foreach ($allowed as $alias) {
        $alias = trim((string) $alias);
        if ($alias === '' || isset($seenAlias[$alias])) {
            continue;
        }
        $seenAlias[$alias] = true;
        $sort++;
        $display = isset($labelMap[$alias]) && $labelMap[$alias] !== '' ? $labelMap[$alias] : $alias;
        $stored = $alias . '|' . $display;
        if (isset($byValue[$alias])) {
            Capsule::table('tblproductconfigoptionssub')->where('id', $byValue[$alias]->id)->update(array(
                'optionname' => $stored,
                'sortorder' => $sort,
                'hidden' => 0,
            ));
            unset($byValue[$alias]);
            continue;
        }
        $sid = (int) Capsule::table('tblproductconfigoptionssub')->insertGetId(array(
            'configid' => $oid,
            'optionname' => $stored,
            'sortorder' => $sort,
            'hidden' => 0,
        ));
        foreach ($currencies as $cid) {
            Capsule::table('tblpricing')->insert(array(
                'type' => 'configoptions',
                'currency' => $cid,
                'relid' => $sid,
                'msetupfee' => 0,
                'qsetupfee' => 0,
                'ssetupfee' => 0,
                'asetupfee' => 0,
                'bsetupfee' => 0,
                'tsetupfee' => 0,
                'monthly' => 0,
                'quarterly' => 0,
                'semiannually' => 0,
                'annually' => 0,
                'biennially' => 0,
                'triennially' => 0,
            ));
        }
    }
    foreach ($byValue as $row) {
        Capsule::table('tblproductconfigoptionssub')->where('id', $row->id)->update(array('hidden' => 1));
    }
}
