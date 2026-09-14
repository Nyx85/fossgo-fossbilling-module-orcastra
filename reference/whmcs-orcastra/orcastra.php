<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/OrcastraClient.php';
require_once __DIR__ . '/lib/OrcastraCatalog.php';

function orcastra_MetaData()
{
    return array(
        'DisplayName' => 'Orcastra',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '443',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Open Orcastra',
        'AdminSingleSignOnLabel' => 'Open Orcastra',
    );
}

function orcastra_ConfigOptions($params = array())
{
    if (!is_array($params)) {
        $params = array();
    }
    try {
        return array(
            'Cluster ID' => array(
                'Type' => 'dropdown',
                'Loader' => 'LoadClusters',
                'SimpleMode' => true,
                'Description' => 'Clusters granted to the API key. Save once, then reload to fetch projects/images.',
            ),
            'LXD Project' => array(
                'Type' => 'dropdown',
                'Loader' => 'LoadProjects',
                'SimpleMode' => true,
                'Description' => 'LXD projects on the selected cluster',
            ),
            'Instance Type' => array(
                'Type' => 'dropdown',
                'Options' => 'virtual-machine,container',
                'Default' => 'virtual-machine',
                'SimpleMode' => true,
                'Description' => 'LXD instance type',
            ),
            'Image' => array(
                'Type' => 'dropdown',
                'Loader' => 'LoadImages',
                'SimpleMode' => true,
                'Description' => 'Local LXD images to offer on the order page',
            ),
            'CPU' => array(
                'Type' => 'dropdown',
                'Options' => '1,2,4,8,16',
                'Default' => '2',
                'SimpleMode' => true,
                'Description' => 'limits.cpu',
            ),
            'Memory' => array(
                'Type' => 'dropdown',
                'Options' => '1GB,2GB,4GB,8GB,16GB,32GB',
                'Default' => '2GB',
                'SimpleMode' => true,
                'Description' => 'limits.memory',
            ),
            'Disk' => array(
                'Type' => 'dropdown',
                'Options' => '10GB,20GB,40GB,80GB,100GB,200GB',
                'Default' => '20GB',
                'SimpleMode' => true,
                'Description' => 'Root disk size',
            ),
            'Storage Pool' => array(
                'Type' => 'dropdown',
                'Loader' => 'LoadPools',
                'SimpleMode' => true,
                'Description' => 'Storage pools on the selected cluster',
            ),
            'Profile' => array(
                'Type' => 'dropdown',
                'Loader' => 'LoadProfiles',
                'SimpleMode' => true,
                'Description' => 'LXD profile on the selected cluster/project',
            ),
            'Dashboard URL' => array(
                'Type' => 'text',
                'Size' => '40',
                'Default' => 'https://orcastra.orca.id',
                'SimpleMode' => true,
                'Description' => 'Optional client-area link',
            ),
            'Network' => array(
                'Type' => 'dropdown',
                'Loader' => 'LoadNetworks',
                'SimpleMode' => true,
                'Description' => 'Managed LXD network (optional; leave empty to use the profile)',
            ),
            'Offer LXD library' => array(
                'Type' => 'yesno',
                'SimpleMode' => true,
                'Description' => 'Tick to also list the public LXD image library (Ubuntu, Debian, Alma, …) on the order page',
            ),
            'Tenant Organization ID' => array(
                'Type' => 'text',
                'Size' => '10',
                'SimpleMode' => true,
                'Description' => 'Orcastra organization_id for auto per-VM tenant policies (leave blank if API key is org-bound)',
            ),
        );
    } catch (Throwable $e) {
        logModuleCall('orcastra', 'ConfigOptions', array(), $e->getMessage(), $e->getFile() . ':' . $e->getLine());
        return array(
            'Cluster ID' => array('Type' => 'text', 'Size' => '40', 'SimpleMode' => true, 'Description' => 'Cluster ID'),
            'LXD Project' => array('Type' => 'text', 'Size' => '25', 'Default' => 'default', 'SimpleMode' => true),
            'Instance Type' => array('Type' => 'dropdown', 'Options' => 'virtual-machine,container', 'Default' => 'virtual-machine', 'SimpleMode' => true),
            'Image' => array('Type' => 'text', 'Size' => '40', 'SimpleMode' => true),
            'CPU' => array('Type' => 'dropdown', 'Options' => '1,2,4,8,16', 'Default' => '2', 'SimpleMode' => true),
            'Memory' => array('Type' => 'dropdown', 'Options' => '1GB,2GB,4GB,8GB,16GB,32GB', 'Default' => '2GB', 'SimpleMode' => true),
            'Disk' => array('Type' => 'dropdown', 'Options' => '10GB,20GB,40GB,80GB,100GB,200GB', 'Default' => '20GB', 'SimpleMode' => true),
            'Storage Pool' => array('Type' => 'text', 'Size' => '25', 'Default' => 'default', 'SimpleMode' => true),
            'Profile' => array('Type' => 'text', 'Size' => '25', 'Default' => 'default', 'SimpleMode' => true),
            'Dashboard URL' => array('Type' => 'text', 'Size' => '40', 'Default' => 'https://orcastra.orca.id', 'SimpleMode' => true),
            'Network' => array('Type' => 'text', 'Size' => '25', 'SimpleMode' => true),
            'Offer LXD library' => array('Type' => 'yesno', 'SimpleMode' => true),
            'Tenant Organization ID' => array('Type' => 'text', 'Size' => '10', 'SimpleMode' => true),
        );
    }
}

// WHMCS 8.10 ProductSetup::getModuleSettingsFields() call_user_func()s the
// Loader string with no module prefix. $mod->call("LoadClusters") still maps
// to orcastra_LoadClusters.
if (!function_exists('LoadClusters')) {
    function LoadClusters($params = array())
    {
        return orcastra_LoadClusters(is_array($params) ? $params : array());
    }
}
if (!function_exists('LoadProjects')) {
    function LoadProjects($params = array())
    {
        return orcastra_LoadProjects(is_array($params) ? $params : array());
    }
}
if (!function_exists('LoadImages')) {
    function LoadImages($params = array())
    {
        return orcastra_LoadImages(is_array($params) ? $params : array());
    }
}
if (!function_exists('LoadPools')) {
    function LoadPools($params = array())
    {
        return orcastra_LoadPools(is_array($params) ? $params : array());
    }
}
if (!function_exists('LoadProfiles')) {
    function LoadProfiles($params = array())
    {
        return orcastra_LoadProfiles(is_array($params) ? $params : array());
    }
}
if (!function_exists('LoadNetworks')) {
    function LoadNetworks($params = array())
    {
        return orcastra_LoadNetworks(is_array($params) ? $params : array());
    }
}

function orcastra_LoadClusters($params = array())
{
    if (!is_array($params)) {
        $params = array();
    }
    try {
        $client = orcastra_client($params);
        $me = $client->whoami();
        $opts = array();
        foreach (isset($me['cluster_permissions']) ? $me['cluster_permissions'] : array() as $grant) {
            if (!is_array($grant) || empty($grant['cluster_id'])) {
                continue;
            }
            $id = $grant['cluster_id'];
            $label = !empty($grant['cluster_name']) ? $grant['cluster_name'] . ' (' . $id . ')' : $id;
            $opts[$id] = $label;
        }
        return orcastra_keep_option($opts, orcastra_opt($params, 1));
    } catch (Throwable $e) {
        logModuleCall('orcastra', 'LoadClusters', orcastra_safe_params($params), $e->getMessage(), null);
        return orcastra_keep_option(array(), orcastra_opt($params, 1));
    }
}

function orcastra_LoadProjects($params = array())
{
    return orcastra_load_named($params, 'list_projects', 'name', 2);
}

function orcastra_LoadPools($params = array())
{
    return orcastra_load_lxd_names($params, '/1.0/storage-pools?recursion=1', 8, false);
}

function orcastra_LoadProfiles($params = array())
{
    if (!is_array($params)) {
        $params = array();
    }
    $cluster = orcastra_opt($params, 1);
    $project = orcastra_opt($params, 2, 'default');
    $current = orcastra_opt($params, 9);
    if ($cluster === '') {
        return orcastra_keep_option(array(), $current);
    }
    try {
        $client = orcastra_client($params);
        $opts = orcastra_profile_name_opts($client, $cluster, $project);
        if (strcasecmp((string) $project, 'default') !== 0) {
            foreach (orcastra_profile_name_opts($client, $cluster, 'default') as $name => $label) {
                if (!isset($opts[$name])) {
                    $opts[$name] = $label;
                }
            }
        }
        return orcastra_keep_option($opts, $current);
    } catch (Throwable $e) {
        logModuleCall('orcastra', '/1.0/profiles?recursion=1', orcastra_safe_params($params), $e->getMessage(), null);
        return orcastra_keep_option(array(), $current);
    }
}

function orcastra_profile_name_opts($client, $cluster, $project)
{
    $opts = array();
    foreach (orcastra_lxd_metadata($client, $cluster, $project, '/1.0/profiles?recursion=1') as $row) {
        if (is_string($row) && $row !== '') {
            $name = preg_replace('#^.*/#', '', $row);
            if ($name !== '') {
                $opts[$name] = $name;
            }
            continue;
        }
        if (!is_array($row) || empty($row['name'])) {
            continue;
        }
        $name = $row['name'];
        $opts[$name] = $name;
    }
    return $opts;
}

function orcastra_LoadNetworks($params = array())
{
    $opts = orcastra_load_lxd_names($params, '/1.0/networks?recursion=1', 11, true);
    if (!isset($opts[''])) {
        $opts = array('' => '-- use profile --') + $opts;
    }
    return $opts;
}

function orcastra_LoadImages($params = array())
{
    if (!is_array($params)) {
        $params = array();
    }
    $cluster = orcastra_opt($params, 1);
    $project = orcastra_opt($params, 2, 'default');
    $current = orcastra_opt($params, 4);
    if ($cluster === '') {
        return orcastra_keep_option(array(), $current);
    }
    try {
        $client = orcastra_client($params);
        $opts = orcastra_catalog_local_images($client, $cluster, $project);
        return orcastra_keep_option($opts, $current);
    } catch (Throwable $e) {
        logModuleCall('orcastra', 'LoadImages', orcastra_safe_params($params), $e->getMessage(), null);
        return orcastra_keep_option(array(), $current);
    }
}

function orcastra_load_named($params, $operation, $field, $keepIndex)
{
    if (!is_array($params)) {
        $params = array();
    }
    $cluster = orcastra_opt($params, 1);
    $project = orcastra_opt($params, 2, 'default');
    $current = orcastra_opt($params, $keepIndex);
    if ($cluster === '') {
        return orcastra_keep_option(array(), $current);
    }
    try {
        $client = orcastra_client($params);
        $res = $client->proxy($cluster, $operation, $project, array(), true);
        $opts = array();
        foreach (orcastra_proxy_rows($res) as $row) {
            if (is_string($row) && $row !== '') {
                $opts[$row] = $row;
                continue;
            }
            if (!is_array($row) || empty($row[$field])) {
                continue;
            }
            $name = $row[$field];
            $opts[$name] = $name;
        }
        return orcastra_keep_option($opts, $current);
    } catch (Throwable $e) {
        logModuleCall('orcastra', $operation, orcastra_safe_params($params), $e->getMessage(), null);
        return orcastra_keep_option(array(), $current);
    }
}

function orcastra_load_lxd_names($params, $path, $keepIndex, $managedOnly = false)
{
    if (!is_array($params)) {
        $params = array();
    }
    $cluster = orcastra_opt($params, 1);
    $project = orcastra_opt($params, 2, 'default');
    $current = orcastra_opt($params, $keepIndex);
    if ($cluster === '') {
        return orcastra_keep_option(array(), $current);
    }
    try {
        $client = orcastra_client($params);
        $opts = array();
        foreach (orcastra_lxd_metadata($client, $cluster, $project, $path) as $row) {
            if (is_string($row) && $row !== '') {
                $opts[$row] = $row;
                continue;
            }
            if (!is_array($row) || empty($row['name'])) {
                continue;
            }
            if ($managedOnly && empty($row['managed'])) {
                continue;
            }
            $name = $row['name'];
            $opts[$name] = $name;
        }
        return orcastra_keep_option($opts, $current);
    } catch (Throwable $e) {
        logModuleCall('orcastra', $path, orcastra_safe_params($params), $e->getMessage(), null);
        return orcastra_keep_option(array(), $current);
    }
}

function orcastra_proxy_rows($res)
{
    if (isset($res['data']) && is_array($res['data'])) {
        return $res['data'];
    }
    if (is_array($res)) {
        return $res;
    }
    return array();
}

function orcastra_keep_option(array $opts, $current)
{
    $head = array();
    $raw = trim((string) $current);
    if ($raw === '' || strpos($raw, '--') === 0) {
        $current = '';
    }
    foreach (orcastra_split_list($current) as $one) {
        if ($one === '' || strpos($one, '--') === 0) {
            continue;
        }
        if (isset($opts[$one])) {
            $head[$one] = $opts[$one];
            unset($opts[$one]);
        } else {
            $head[$one] = $one;
        }
    }
    $opts = $head + $opts;
    if (!$opts) {
        $opts = array('' => '-- save cluster, then reload --');
    }
    return $opts;
}

function orcastra_TestConnection(array $params)
{
    try {
        $client = orcastra_client($params);
        $me = $client->whoami();
        $name = isset($me['app_name']) ? $me['app_name'] : 'key';
        return array(
            'success' => true,
            'error' => '',
        );
    } catch (Exception $e) {
        logModuleCall('orcastra', 'TestConnection', orcastra_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return array(
            'success' => false,
            'error' => $e->getMessage(),
        );
    }
}

function orcastra_AdminCustomButtonArray()
{
    return array(
        'Start' => 'StartInstance',
        'Stop' => 'StopInstance',
        'Restart' => 'RestartInstance',
        'Console' => 'OpenConsole',
        'Terminal' => 'OpenTerminal',
        'Metrics' => 'RefreshMetrics',
    );
}

function orcastra_ClientAreaCustomButtonArray()
{
    return array(
        'Start' => 'StartInstance',
        'Stop' => 'StopInstance',
        'Restart' => 'RestartInstance',
        'Console' => 'OpenConsole',
        'Terminal' => 'OpenTerminal',
        'Refresh Metrics' => 'RefreshMetrics',
    );
}


/**
 * After VM create: grant the WHMCS client a per-instance tenant policy (view/operate/console).
 * Soft-fails if the integrations endpoint is not deployed yet.
 */
function orcastra_provision_vm_access(array $params, $cluster, $project, $instanceName)
{
    try {
        $email = '';
        if (!empty($params['clientsdetails']['email'])) {
            $email = trim((string) $params['clientsdetails']['email']);
        }
        if ($email === '') {
            return;
        }
        $client = orcastra_client($params);
        $payload = array(
            'user_email' => $email,
            'cluster_id' => $cluster,
            'project' => $project ?: 'default',
            'instance_name' => $instanceName,
            'service_id' => (string) $params['serviceid'],
            'permissions' => array('view', 'operate', 'console'),
        );
        $orgId = orcastra_opt($params, 13, '');
        if ($orgId !== '' && ctype_digit((string) $orgId)) {
            $payload['organization_id'] = (int) $orgId;
        }
        $res = $client->provisionVmAccess($payload);
        logModuleCall('orcastra', 'provisionVmAccess', $payload, $res, null);
    } catch (Exception $e) {
        // Do not fail CreateAccount if policy API is missing/unavailable.
        logModuleCall('orcastra', 'provisionVmAccess', array(
            'serviceid' => isset($params['serviceid']) ? $params['serviceid'] : null,
            'instance' => $instanceName,
        ), $e->getMessage(), null);
    }
}

function orcastra_revoke_vm_access(array $params, $row)
{
    try {
        $email = '';
        if (!empty($params['clientsdetails']['email'])) {
            $email = trim((string) $params['clientsdetails']['email']);
        }
        $client = orcastra_client($params);
        $payload = array(
            'user_email' => $email,
            'cluster_id' => $row->cluster_id,
            'project' => $row->project ?: 'default',
            'instance_name' => $row->instance_name,
            'service_id' => (string) $params['serviceid'],
        );
        $orgId = orcastra_opt($params, 13, '');
        if ($orgId !== '' && ctype_digit((string) $orgId)) {
            $payload['organization_id'] = (int) $orgId;
        }
        $res = $client->revokeVmAccess($payload);
        logModuleCall('orcastra', 'revokeVmAccess', $payload, $res, null);
    } catch (Exception $e) {
        logModuleCall('orcastra', 'revokeVmAccess', array(
            'serviceid' => isset($params['serviceid']) ? $params['serviceid'] : null,
        ), $e->getMessage(), null);
    }
}

function orcastra_CreateAccount(array $params)
{
    try {
        orcastra_ensure_table();
        $existing = orcastra_row($params['serviceid']);
        if ($existing) {
            return 'success';
        }

        $client = orcastra_client($params);
        $cluster = orcastra_opt($params, 1);
        $project = orcastra_opt($params, 2, 'default');
        $type = orcastra_opt($params, 3, 'virtual-machine');
        $image = orcastra_chosen_image($params);
        $cpu = orcastra_chosen_cpu($params);
        $memory = orcastra_chosen_memory($params);
        $disk = orcastra_chosen_disk($params);
        $pool = orcastra_opt($params, 8, 'nvme');
        $profileRaw = orcastra_opt($params, 9, 'default');
        $network = orcastra_opt($params, 11, '');
        if ($cluster === '') {
            throw new Exception('Product is missing Cluster ID');
        }

        $name = orcastra_instance_name($params['serviceid']);
        $profiles = array_values(array_filter(array_map('trim', explode(',', $profileRaw))));
        if (!$profiles) {
            $profiles = array('default');
        }

        $create = array(
            'name' => $name,
            'type' => $type,
            'source' => orcastra_image_source($image),
            'config' => array(
                'limits.cpu' => (string) $cpu,
                'limits.memory' => (string) $memory,
                'user.orcastra.whmcs_service' => (string) $params['serviceid'],
                'user.orcastra.client_email' => (string) $params['clientsdetails']['email'],
            ),
            'devices' => array(
                'root' => array(
                    'type' => 'disk',
                    'path' => '/',
                    'pool' => $pool,
                    'size' => (string) $disk,
                ),
            ),
            'profiles' => $profiles,
        );
        if ($network !== '') {
            $create['devices']['eth0'] = array(
                'type' => 'nic',
                'network' => $network,
            );
        }

        $createdName = $name;
        $result = null;
        try {
            $result = $client->proxy($cluster, 'create_instance', $project, $create, true);
            if (isset($result['data']['name']) && $result['data']['name']) {
                $createdName = $result['data']['name'];
            }
        } catch (Exception $e) {
            // Create may succeed on LXD while the API read times out; adopt if present.
            $msg = $e->getMessage();
            $adopt = (stripos($msg, 'already exists') !== false)
                || (stripos($msg, 'timed out') !== false)
                || (stripos($msg, 'timeout') !== false);
            if (!$adopt) {
                throw $e;
            }
            try {
                $info = $client->proxy($cluster, 'get_instance', $project, array('name' => $name), true);
                if (empty($info['success']) && empty($info['data'])) {
                    throw $e;
                }
                $createdName = $name;
                $result = array(
                    'success' => true,
                    'data' => isset($info['data']) ? $info['data'] : array('name' => $name),
                    'adopted' => true,
                    'adopt_reason' => $msg,
                );
            } catch (Exception $e2) {
                if (stripos($e2->getMessage(), 'not found') !== false || stripos($msg, 'already exists') === false) {
                    throw $e;
                }
                throw $e;
            }
        }

        try {
            $client->proxy($cluster, 'start_instance', $project, array('name' => $createdName), true);
        } catch (Exception $e) {
            // create may already start the instance depending on LXD defaults
        }

        Capsule::table('mod_orcastra_instances')->insert(array(
            'service_id' => $params['serviceid'],
            'instance_name' => $createdName,
            'cluster_id' => $cluster,
            'project' => $project,
            'instance_type' => $type,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ));

        Capsule::table('tblhosting')->where('id', $params['serviceid'])->update(array(
            'domain' => $createdName,
            'username' => $createdName,
        ));

        orcastra_provision_vm_access($params, $cluster, $project, $createdName);

        logModuleCall('orcastra', 'CreateAccount', orcastra_safe_params($params), $result, null);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('orcastra', 'CreateAccount', orcastra_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Failed to create instance: ' . $e->getMessage();
    }
}

function orcastra_SuspendAccount(array $params)
{
    return orcastra_lifecycle($params, 'stop_instance', 'suspended', array('force' => true));
}

function orcastra_UnsuspendAccount(array $params)
{
    return orcastra_lifecycle($params, 'start_instance', 'active');
}

function orcastra_TerminateAccount(array $params)
{
    try {
        orcastra_ensure_table();
        $row = orcastra_row($params['serviceid']);
        if (!$row) {
            return 'success';
        }
        $client = orcastra_client($params);
        try {
            $client->proxy($row->cluster_id, 'stop_instance', $row->project, array(
                'name' => $row->instance_name,
                'force' => true,
            ), true);
        } catch (Exception $e) {
            // already stopped is fine
        }
        $client->proxy($row->cluster_id, 'delete_instance', $row->project, array(
            'name' => $row->instance_name,
        ), true);
        orcastra_revoke_vm_access($params, $row);
        Capsule::table('mod_orcastra_instances')->where('service_id', $params['serviceid'])->delete();
        return 'success';
    } catch (Exception $e) {
        logModuleCall('orcastra', 'TerminateAccount', orcastra_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Failed to terminate instance: ' . $e->getMessage();
    }
}

function orcastra_ChangePackage(array $params)
{
    try {
        orcastra_ensure_table();
        $row = orcastra_row($params['serviceid']);
        if (!$row) {
            throw new Exception('Instance record not found');
        }
        $client = orcastra_client($params);
        $cpu = orcastra_chosen_cpu($params);
        $memory = orcastra_chosen_memory($params);
        $disk = orcastra_chosen_disk($params);
        $pool = orcastra_opt($params, 8, 'nvme');
        $client->proxy($row->cluster_id, 'update_instance', $row->project, array(
            'name' => $row->instance_name,
            'config' => array(
                'limits.cpu' => (string) $cpu,
                'limits.memory' => (string) $memory,
            ),
            'devices' => array(
                'root' => array(
                    'type' => 'disk',
                    'path' => '/',
                    'pool' => $pool,
                    'size' => (string) $disk,
                ),
            ),
        ), true);
        Capsule::table('mod_orcastra_instances')->where('service_id', $params['serviceid'])->update(array(
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        return 'success';
    } catch (Exception $e) {
        logModuleCall('orcastra', 'ChangePackage', orcastra_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Failed to resize instance: ' . $e->getMessage();
    }
}

function orcastra_StartInstance(array $params)
{
    return orcastra_lifecycle($params, 'start_instance', 'active');
}

function orcastra_StopInstance(array $params)
{
    return orcastra_lifecycle($params, 'stop_instance', 'stopped', array('force' => true));
}

function orcastra_RestartInstance(array $params)
{
    return orcastra_lifecycle($params, 'restart_instance', 'active', array('force' => true));
}

function orcastra_OpenConsole(array $params)
{
    try {
        orcastra_ensure_table();
        $row = orcastra_row($params['serviceid']);
        if (!$row) {
            return 'Instance has not been provisioned yet.';
        }
        $url = orcastra_mint_access_redirect($params, $row, 'console');
        orcastra_redirect_external($url);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('orcastra', 'OpenConsole', orcastra_safe_params($params), $e->getMessage(), null);
        return 'Console failed: ' . $e->getMessage();
    }
}

function orcastra_OpenTerminal(array $params)
{
    try {
        orcastra_ensure_table();
        $row = orcastra_row($params['serviceid']);
        if (!$row) {
            return 'Instance has not been provisioned yet.';
        }
        // Text terminal (exec) needs guest agent. For VMs open graphics console instead.
        $type = $row->instance_type ?: 'virtual-machine';
        $mode = ($type !== 'container') ? 'console' : 'terminal';
        $url = orcastra_mint_access_redirect($params, $row, $mode);
        orcastra_redirect_external($url);
        return 'success';
    } catch (Exception $e) {
        logModuleCall('orcastra', 'OpenTerminal', orcastra_safe_params($params), $e->getMessage(), null);
        return 'Terminal failed: ' . $e->getMessage();
    }
}

function orcastra_RefreshMetrics(array $params)
{
    try {
        orcastra_ensure_table();
        $row = orcastra_row($params['serviceid']);
        if (!$row) {
            return 'Instance has not been provisioned yet.';
        }
        $client = orcastra_client($params);
        $m = orcastra_fetch_metrics($client, $row->cluster_id, $row->project, $row->instance_name);
        if (!empty($m['error'])) {
            return 'Metrics failed: ' . $m['error'];
        }
        // Returning a non-success string is shown as Module Command Error in admin.
        // Live fields refresh on page reload after success.
        logModuleCall('orcastra', 'RefreshMetrics', array(
            'serviceid' => $params['serviceid'],
            'instance' => $row->instance_name,
        ), $m, null);
        return 'success';
    } catch (Exception $e) {
        return 'Metrics failed: ' . $e->getMessage();
    }
}

function orcastra_ClientArea(array $params)
{
    try {
        orcastra_ensure_table();
        $row = orcastra_row($params['serviceid']);
        if (!$row) {
            return array(
                'templatefile' => 'templates/clientarea',
                'vars' => array('error' => 'Instance has not been provisioned yet.'),
            );
        }
        $status = $row->status;
        $type = $row->instance_type;
        $metrics = array(
            'memory' => 'n/a',
            'disk' => 'n/a',
            'cpu' => 'n/a',
            'network' => 'n/a',
            'processes' => 'n/a',
        );
        try {
            $client = orcastra_client($params);
            $info = $client->proxy($row->cluster_id, 'get_instance', $row->project, array(
                'name' => $row->instance_name,
            ), true);
            if (isset($info['data']['status'])) {
                $status = $info['data']['status'];
            }
            if (isset($info['data']['instance_type'])) {
                $type = $info['data']['instance_type'];
            } elseif (isset($info['data']['type'])) {
                $type = $info['data']['type'];
            }
            $metrics = orcastra_fetch_metrics($client, $row->cluster_id, $row->project, $row->instance_name);
            if (!empty($metrics['status'])) {
                $status = $metrics['status'];
            }
        } catch (Exception $e) {
            // keep stored status
        }
        $dash = orcastra_opt($params, 10, '');
        $sid = (int) $params['serviceid'];
        // Prefer server-side custom actions (mint ticket) — never embed bare console URLs.
        $consoleAction = 'clientarea.php?action=productdetails&id=' . $sid . '&modop=custom&a=OpenConsole';
        $terminalAction = 'clientarea.php?action=productdetails&id=' . $sid . '&modop=custom&a=OpenTerminal';
        return array(
            'templatefile' => 'templates/clientarea',
            'vars' => array(
                'instance_name' => $row->instance_name,
                'instance_status' => $status,
                'instance_type' => $type,
                'cluster_id' => $row->cluster_id,
                'project' => $row->project,
                'service_id' => $sid,
                'dashboard_url' => $dash,
                'console_url' => $consoleAction,
                'terminal_url' => $terminalAction,
                'metric_cpu' => $metrics['cpu'],
                'metric_memory' => $metrics['memory'],
                'metric_disk' => $metrics['disk'],
                'metric_network' => $metrics['network'],
                'metric_processes' => $metrics['processes'],
                'metric_error' => isset($metrics['error']) ? $metrics['error'] : '',
                'metric_memory_pct' => isset($metrics['memory_pct']) ? $metrics['memory_pct'] : '',
                'metric_disk_pct' => isset($metrics['disk_pct']) ? $metrics['disk_pct'] : '',
                'metric_memory_used_label' => isset($metrics['memory_used_label']) ? $metrics['memory_used_label'] : '',
                'metric_memory_total_label' => isset($metrics['memory_total_label']) ? $metrics['memory_total_label'] : '',
                'metric_disk_used_label' => isset($metrics['disk_used_label']) ? $metrics['disk_used_label'] : '',
                'metric_disk_total_label' => isset($metrics['disk_total_label']) ? $metrics['disk_total_label'] : '',
            ),
        );
    } catch (Exception $e) {
        logModuleCall('orcastra', 'ClientArea', orcastra_safe_params($params), $e->getMessage(), null);
        return array(
            'templatefile' => 'templates/clientarea',
            'vars' => array('error' => 'Unable to load instance information'),
        );
    }
}

function orcastra_AdminServicesTabFields(array $params)
{
    orcastra_ensure_table();
    $row = orcastra_row($params['serviceid']);
    if (!$row) {
        return array('Orcastra' => 'Not provisioned');
    }
    $status = $row->status;
    $metrics = array(
        'memory' => 'n/a',
        'disk' => 'n/a',
        'cpu' => 'n/a',
        'network' => 'n/a',
        'processes' => 'n/a',
    );
    try {
        $client = orcastra_client($params);
        $info = $client->proxy($row->cluster_id, 'get_instance', $row->project, array(
            'name' => $row->instance_name,
        ), true);
        if (isset($info['data']['status'])) {
            $status = $info['data']['status'];
        }
        $metrics = orcastra_fetch_metrics($client, $row->cluster_id, $row->project, $row->instance_name);
        if (!empty($metrics['status'])) {
            $status = $metrics['status'];
        }
    } catch (Exception $e) {
        $status = $row->status . ' (live lookup failed)';
    }
    $fields = array(
        'Instance' => $row->instance_name,
        'Cluster' => $row->cluster_id,
        'Project' => $row->project,
        'Type' => $row->instance_type,
        'Status' => $status,
        'CPU' => $metrics['cpu'],
        'Memory' => $metrics['memory'],
        'Disk' => $metrics['disk'],
        'Network' => $metrics['network'],
        'Processes' => (string) $metrics['processes'],
        'Console / Terminal' => 'Use the <strong>Console</strong> or <strong>Terminal</strong> module buttons above (mints a short-lived Mini ticket; bare deep links are disabled).',
    );
    if (!empty($metrics['error'])) {
        $fields['Metrics Error'] = $metrics['error'];
    }
    return $fields;
}



function orcastra_redirect_external($url)
{
    $url = (string) $url;
    if ($url === '') {
        throw new Exception('Empty redirect URL');
    }
    // Prefer HTTP redirect; fall back to JS if headers already sent (common in WHMCS admin).
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    $js = json_encode($url, JSON_UNESCAPED_SLASHES);
    echo '<script type="text/javascript">window.location.href=' . $js . ';</script>';
    echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    exit;
}

function orcastra_format_bytes($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes < 0) {
        $bytes = 0;
    }
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $decimals = ($i === 0) ? 0 : 1;
    return number_format($bytes, $decimals) . ' ' . $units[$i];
}

function orcastra_format_cpu_ns($ns)
{
    $ns = (float) $ns;
    if ($ns <= 0) {
        return '0s';
    }
    $sec = $ns / 1e9;
    if ($sec < 60) {
        return number_format($sec, 1) . 's CPU';
    }
    if ($sec < 3600) {
        return number_format($sec / 60, 1) . 'm CPU';
    }
    return number_format($sec / 3600, 1) . 'h CPU';
}

/**
 * Live LXD instance state via integrations raw-proxy.
 * @return array{status:string,memory:string,memory_raw:array,memory_pct:float|string,memory_used_label:string,memory_total_label:string,disk:string,disk_raw:array,disk_pct:float|string,disk_used_label:string,disk_total_label:string,cpu:string,network:string,processes:int|string,error?:string}
 */
function orcastra_fetch_metrics($client, $clusterId, $project, $instanceName)
{
    $out = array(
        'status' => '',
        'memory' => 'n/a',
        'memory_raw' => array(),
        'memory_pct' => '',
        'memory_used_label' => '',
        'memory_total_label' => '',
        'disk' => 'n/a',
        'disk_raw' => array(),
        'disk_pct' => '',
        'disk_used_label' => '',
        'disk_total_label' => '',
        'cpu' => 'n/a',
        'network' => 'n/a',
        'processes' => 'n/a',
    );
    try {
        $path = '/1.0/instances/' . rawurlencode($instanceName) . '/state';
        $raw = $client->rawProxy($clusterId, 'GET', $path, $project ?: 'default');
        $meta = array();
        if (isset($raw['data']['metadata']) && is_array($raw['data']['metadata'])) {
            $meta = $raw['data']['metadata'];
        } elseif (isset($raw['metadata']) && is_array($raw['metadata'])) {
            $meta = $raw['metadata'];
        }
        if (!$meta) {
            $out['error'] = 'Empty state payload';
            return $out;
        }
        $out['status'] = isset($meta['status']) ? (string) $meta['status'] : '';
        $out['processes'] = isset($meta['processes']) ? $meta['processes'] : 'n/a';

        $mem = isset($meta['memory']) && is_array($meta['memory']) ? $meta['memory'] : array();
        $out['memory_raw'] = $mem;
        $usage = isset($mem['usage']) ? (float) $mem['usage'] : 0;
        $total = isset($mem['total']) ? (float) $mem['total'] : 0;
        $out['memory_used_label'] = orcastra_format_bytes($usage);
        if ($total > 0) {
            $pct = round(($usage / $total) * 100, 1);
            $out['memory_pct'] = $pct;
            $out['memory_total_label'] = orcastra_format_bytes($total);
            $out['memory'] = $out['memory_used_label'] . ' / ' . $out['memory_total_label'] . ' (' . $pct . '%)';
        } else {
            $out['memory_total_label'] = '';
            $out['memory_pct'] = '';
            $out['memory'] = $out['memory_used_label'];
        }

        $disk = isset($meta['disk']) && is_array($meta['disk']) ? $meta['disk'] : array();
        $out['disk_raw'] = $disk;
        if (isset($disk['root']) && is_array($disk['root'])) {
            $du = isset($disk['root']['usage']) ? (float) $disk['root']['usage'] : 0;
            $dt = isset($disk['root']['total']) ? (float) $disk['root']['total'] : 0;
            $out['disk_used_label'] = orcastra_format_bytes($du);
            if ($dt > 0) {
                $pct = round(($du / $dt) * 100, 1);
                $out['disk_pct'] = $pct;
                $out['disk_total_label'] = orcastra_format_bytes($dt);
                $out['disk'] = $out['disk_used_label'] . ' / ' . $out['disk_total_label'] . ' (' . $pct . '%)';
            } else {
                $out['disk_total_label'] = '';
                $out['disk_pct'] = '';
                $out['disk'] = $out['disk_used_label'];
            }
        }

        $cpu = isset($meta['cpu']) && is_array($meta['cpu']) ? $meta['cpu'] : array();
        $out['cpu'] = isset($cpu['usage']) ? orcastra_format_cpu_ns($cpu['usage']) : 'n/a';

        $netParts = array();
        if (!empty($meta['network']) && is_array($meta['network'])) {
            foreach ($meta['network'] as $iface => $info) {
                if (!is_array($info) || $iface === 'lo') {
                    continue;
                }
                $addrs = array();
                if (!empty($info['addresses']) && is_array($info['addresses'])) {
                    foreach ($info['addresses'] as $a) {
                        if (!is_array($a) || empty($a['address'])) {
                            continue;
                        }
                        if (isset($a['family']) && $a['family'] === 'inet') {
                            $addrs[] = $a['address'];
                        }
                    }
                }
                $rx = isset($info['counters']['bytes_received']) ? orcastra_format_bytes($info['counters']['bytes_received']) : '0 B';
                $tx = isset($info['counters']['bytes_sent']) ? orcastra_format_bytes($info['counters']['bytes_sent']) : '0 B';
                $ip = $addrs ? implode(', ', $addrs) : 'no-ipv4';
                $netParts[] = $iface . ': ' . $ip . ' (↓' . $rx . ' ↑' . $tx . ')';
            }
        }
        $out['network'] = $netParts ? implode(' · ', $netParts) : 'n/a';
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
    }
    return $out;
}

/**
 * Mint Mini console-access ticket and return redirect_url.
 * Does NOT fall back to unticketed deep links (those cause auth retry loops).
 *
 * @param string $mode console|terminal
 */
function orcastra_mint_access_redirect(array $params, $row, $mode)
{
    $mode = ($mode === 'terminal') ? 'terminal' : 'console';
    $type = $row->instance_type ?: 'virtual-machine';
    if ($type === 'virtual_machine') {
        $type = 'virtual-machine';
    }
    $isContainer = ($type === 'container');
    $tab = $isContainer ? 'text' : 'graphics';

    $dashboard = rtrim(orcastra_opt($params, 10, 'https://orcastra.orca.id'), '/');
    if ($dashboard === '') {
        $dashboard = 'https://orcastra.orca.id';
    }

    $payload = array(
        'instance' => $row->instance_name,
        'cluster_id' => $row->cluster_id,
        'project' => $row->project ?: 'default',
        'mode' => $mode,
        'console_type' => 'vga',
        'dashboard_base' => $dashboard,
        'instance_type' => $type,
        'tab' => $tab,
    );
    if (!empty($params['clientsdetails']['email'])) {
        $payload['client_email'] = trim((string) $params['clientsdetails']['email']);
    }

    $client = orcastra_client($params);
    $res = $client->consoleAccess($payload);
    if (!is_array($res) || empty($res['redirect_url'])) {
        throw new Exception('Console access API did not return redirect_url');
    }
    $url = (string) $res['redirect_url'];
    if (strpos($url, 'ticket=') === false) {
        throw new Exception('Console access redirect_url missing ticket');
    }
    return $url;
}

/**
 * @deprecated Bare unticketed deep links cause auth retry loops. Prefer orcastra_mint_access_redirect.
 */
function orcastra_console_url(array $params, $row)
{
    $base = rtrim(orcastra_opt($params, 10, 'https://orcastra.orca.id'), '/');
    if ($base === '') {
        $base = 'https://orcastra.orca.id';
    }
    $id = rawurlencode($row->instance_name);
    $type = $row->instance_type ?: 'virtual-machine';
    $isContainer = ($type === 'container');
    $q = http_build_query(array(
        'instance' => $row->instance_name,
        'host' => $row->cluster_id,
        'project' => $row->project ?: 'default',
        'type' => $type,
        // VMs must use SPICE graphics; text tab needs LXD guest agent (often not ready yet).
        'tab' => $isContainer ? 'text' : 'graphics',
    ));
    return $base . '/console/' . $id . '?' . $q;
}

/**
 * @deprecated Bare unticketed deep links cause auth retry loops. Prefer orcastra_mint_access_redirect.
 */
function orcastra_terminal_url(array $params, $row)
{
    $base = rtrim(orcastra_opt($params, 10, 'https://orcastra.orca.id'), '/');
    if ($base === '') {
        $base = 'https://orcastra.orca.id';
    }
    $id = rawurlencode($row->instance_name);
    $q = http_build_query(array(
        'instance' => $row->instance_name,
        'host' => $row->cluster_id,
        'project' => $row->project ?: 'default',
        'type' => $row->instance_type ?: 'virtual-machine',
    ));
    return $base . '/terminal/' . $id . '?' . $q;
}

function orcastra_client($params)
{
    if (!is_array($params)) {
        $params = array();
    }
    $params = orcastra_hydrate_server($params);
    $hash = isset($params['serveraccesshash']) ? (string) $params['serveraccesshash'] : '';
    $pass = isset($params['serverpassword']) ? (string) $params['serverpassword'] : '';
    $secret = $hash !== '' ? $hash : $pass;
    $host = isset($params['serverhostname']) ? $params['serverhostname'] : '';
    $user = isset($params['serverusername']) ? $params['serverusername'] : '';
    return new OrcastraClient($host, $user, $secret);
}

function orcastra_hydrate_server($params)
{
    if (!is_array($params)) {
        $params = array();
    }
    $host = isset($params['serverhostname']) ? trim((string) $params['serverhostname']) : '';
    $user = isset($params['serverusername']) ? trim((string) $params['serverusername']) : '';
    $pass = isset($params['serverpassword']) ? (string) $params['serverpassword'] : '';
    $hash = isset($params['serveraccesshash']) ? (string) $params['serveraccesshash'] : '';
    if ($host !== '' && $user !== '' && ($pass !== '' || $hash !== '')) {
        return $params;
    }

    $server = null;
    $groupId = 0;
    if (!empty($params['servergroup'])) {
        $groupId = (int) $params['servergroup'];
    } elseif (!empty($params['packageid'])) {
        $groupId = (int) Capsule::table('tblproducts')->where('id', $params['packageid'])->value('servergroup');
    } else {
        $groupId = (int) Capsule::table('tblproducts')->where('servertype', 'orcastra')->orderBy('id', 'desc')->value('servergroup');
    }
    if ($groupId) {
        $serverId = Capsule::table('tblservergroupsrel')->where('groupid', $groupId)->value('serverid');
        if ($serverId) {
            $server = Capsule::table('tblservers')->where('id', $serverId)->first();
        }
    }
    if (!$server) {
        $server = Capsule::table('tblservers')->where('type', 'orcastra')->where('disabled', 0)->orderBy('id')->first();
    }
    if (!$server) {
        throw new Exception('No Orcastra server is configured in WHMCS');
    }

    $params['serverhostname'] = $server->hostname;
    $params['serverusername'] = $server->username;
    $params['serverpassword'] = function_exists('decrypt') ? decrypt($server->password) : $server->password;
    $params['serveraccesshash'] = $server->accesshash;
    return $params;
}

function orcastra_opt($params, $n, $default = '')
{
    if (!is_array($params)) {
        $params = array();
    }
    foreach (array('configoption' . $n, 'packageconfigoption' . $n, 'configoption' . $n . '_value') as $key) {
        if (isset($params[$key]) && $params[$key] !== '') {
            return trim((string) $params[$key]);
        }
    }
    // Product Module Settings page stores saved values under the product row.
    if (empty($params['configoption' . $n]) && !empty($params['pid'])) {
        $saved = Capsule::table('tblproducts')->where('id', $params['pid'])->value('configoption' . $n);
        if ($saved) {
            return trim((string) $saved);
        }
    }
    if (empty($params['configoption' . $n])) {
        $saved = Capsule::table('tblproducts')->where('servertype', 'orcastra')->orderBy('id', 'desc')->value('configoption' . $n);
        if ($saved) {
            return trim((string) $saved);
        }
    }
    return $default;
}

function orcastra_instance_name($serviceId)
{
    return 'vm' . (int) $serviceId;
}

function orcastra_row($serviceId)
{
    return Capsule::table('mod_orcastra_instances')->where('service_id', $serviceId)->first();
}

function orcastra_lifecycle(array $params, $operation, $status, array $extra = array())
{
    try {
        orcastra_ensure_table();
        $row = orcastra_row($params['serviceid']);
        if (!$row) {
            throw new Exception('Instance record not found');
        }
        $client = orcastra_client($params);
        $payload = array_merge(array('name' => $row->instance_name), $extra);
        $client->proxy($row->cluster_id, $operation, $row->project, $payload, true);
        Capsule::table('mod_orcastra_instances')->where('service_id', $params['serviceid'])->update(array(
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ));
        return 'success';
    } catch (Exception $e) {
        logModuleCall('orcastra', $operation, orcastra_safe_params($params), $e->getMessage(), $e->getTraceAsString());
        return 'Failed: ' . $e->getMessage();
    }
}

function orcastra_safe_params(array $params)
{
    $copy = $params;
    foreach (array('serverpassword', 'serveraccesshash', 'password') as $k) {
        if (isset($copy[$k])) {
            $copy[$k] = '***';
        }
    }
    return $copy;
}

function orcastra_ensure_table()
{
    if (Capsule::schema()->hasTable('mod_orcastra_instances')) {
        return;
    }
    Capsule::schema()->create('mod_orcastra_instances', function ($table) {
        $table->increments('id');
        $table->unsignedInteger('service_id')->unique();
        $table->string('instance_name', 64);
        $table->string('cluster_id', 128);
        $table->string('project', 64)->default('default');
        $table->string('instance_type', 32)->default('virtual-machine');
        $table->string('status', 32)->default('active');
        $table->timestamp('created_at')->nullable();
        $table->timestamp('updated_at')->nullable();
    });
}
