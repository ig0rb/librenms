<?php

/**
 * Powerconnect.php
 *
 * Dell PowerConnect
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2018 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace LibreNMS\OS;

use App\Facades\PortCache;
use App\Models\PortsNac;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LibreNMS\Device\Processor;
use LibreNMS\Interfaces\Discovery\ProcessorDiscovery;
use LibreNMS\Interfaces\Polling\NacPolling;
use LibreNMS\Interfaces\Polling\ProcessorPolling;
use LibreNMS\OS;
use LibreNMS\OS\Traits\VxworksProcessorUsage;
use SnmpQuery;

class Powerconnect extends OS implements ProcessorDiscovery, ProcessorPolling, NacPolling
{
    // pull in VxWorks processor parsing, but allow us to extend it
    use VxworksProcessorUsage {
        VxworksProcessorUsage::discoverProcessors as discoverVxworksProcessors;
        VxworksProcessorUsage::pollProcessors as pollVxworksProcessors;
    }

    /**
     * Discover processors.
     * Returns an array of LibreNMS\Device\Processor objects that have been discovered
     *
     * @return array Processors
     */
    public function discoverProcessors()
    {
        $device = $this->getDeviceArray();
        if (Str::startsWith($device['sysObjectID'], [
            '.1.3.6.1.4.1.674.10895.3020',
            '.1.3.6.1.4.1.674.10895.3021',
            '.1.3.6.1.4.1.674.10895.3028',
            '.1.3.6.1.4.1.674.10895.3030',
            '.1.3.6.1.4.1.674.10895.3031',
        ])) {
            d_echo('Dell Powerconnect 55xx');

            return [
                Processor::discover(
                    'powerconnect-nv',
                    $this->getDeviceId(),
                    '.1.3.6.1.4.1.89.1.7.0',
                    0
                ),
            ];
        } elseif (Str::startsWith($device['sysObjectID'], [
            '.1.3.6.1.4.1.674.10895.3024',
            '.1.3.6.1.4.1.674.10895.3042',
            '.1.3.6.1.4.1.674.10895.3053',
            '.1.3.6.1.4.1.674.10895.3054',
            '.1.3.6.1.4.1.674.10895.3056',
            '.1.3.6.1.4.1.674.10895.3058',
            '.1.3.6.1.4.1.674.10895.3065',
            '.1.3.6.1.4.1.674.10895.3046',
            '.1.3.6.1.4.1.674.10895.3063',
            '.1.3.6.1.4.1.674.10895.3064',
            '.1.3.6.1.4.1.674.10895.3065',
            '.1.3.6.1.4.1.674.10895.3066',
            '.1.3.6.1.4.1.674.10895.3078',
            '.1.3.6.1.4.1.674.10895.3079',
            '.1.3.6.1.4.1.674.10895.3080',
            '.1.3.6.1.4.1.674.10895.3081',
            '.1.3.6.1.4.1.674.10895.3082',
            '.1.3.6.1.4.1.674.10895.3083',
        ])) {
            return $this->discoverVxworksProcessors('.1.3.6.1.4.1.674.10895.5000.2.6132.1.1.1.1.4.9.0');
        }

        return $this->discoverVxworksProcessors('.1.3.6.1.4.1.674.10895.5000.2.6132.1.1.1.1.4.4.0');
    }

    /**
     * Poll processor data.  This can be implemented if custom polling is needed.
     *
     * @param  array  $processors  Array of processor entries from the database that need to be polled
     * @return array of polled data
     */
    public function pollProcessors(array $processors)
    {
        $data = [];

        foreach ($processors as $processor) {
            if ($processor['processor_type'] == 'powerconnect-nv') {
                $data[$processor['processor_id']] = snmp_get($this->getDeviceArray(), $processor['processor_oid'], '-Oqv');
            } else {
                $data += $this->pollVxworksProcessors([$processor]);
            }
        }

        return $data;
    }

    private static function decode_string(string $s): string
    {
        $s = str_replace("\n", ' ', $s);
        $characters = explode(' ', $s);

        $res = '';
        foreach ($characters as $char) {
            if (trim($char) === '') {
                continue;
            }

            if ($char === '00') {
                break;
            }
            $res .= chr(hexdec($char));
        }

        return $res;
    }

    public function pollNac()
    {
        $nac = new Collection();

        $authMgrEnabled = SnmpQuery::mibs(['DNOS-AUTHENTICATION-MANAGER-MIB'])->mibDir('dell')->hideMib()->enumStrings()->get('agentAuthMgrAdminMode.0')->value();
        if ($authMgrEnabled !== 'enable') {
            d_echo('AuthMgr not enabled.');

            return $nac;
        }

        $table = SnmpQuery::mibs(['DNOS-AUTHENTICATION-MANAGER-MIB'])->mibDir('dell')->hideMib()->enumStrings()->walk('agentAuthMgrClientStatusTable')->table(2);
        if (count($table) === 0) {
            d_echo('Client status table is empty, not processing NAC entries.');

            return $nac;
        }

        $hostmode = SnmpQuery::mibs(['DNOS-AUTHENTICATION-MANAGER-MIB'])->mibDir('dell')->hideMib()->enumStrings()->walk('agentAuthMgrPortHostMode')->valuesByIndex();
        foreach ($table as &$row) {
            if ($row['agentAuthMgrClientAuthState'] === 'success') {
                $row['agentAuthMgrClientAuthState'] = 'authcSuccess';
            } elseif ($row['agentAuthMgrClientAuthState'] === 'failed') {
                $row['agentAuthMgrClientAuthState'] = 'authcFailed';
            }

            $row['agentAuthMgrClientAuthstatus'] = match ($row['agentAuthMgrClientAuthstatus']) {
                'authorized' => 'authorizationSuccess',
                'unauthorized' => 'authorizationFailed',
                default => $row['agentAuthMgrClientAuthstatus']
            };

            $row['agentAuthMgrPortHostMode'] = $hostmode[$row['agentAuthMgrInterface']]['agentAuthMgrPortHostMode'] ?? '';
        }

        foreach ($table as $authIndex => $data) {
            $mac_address = $data['agentAuthMgrClientMacAddress'];

            $nac->put($mac_address, new PortsNac([
                'port_id' => (int) PortCache::getIdFromIfIndex($data['agentAuthMgrInterface'], $this->getDevice()),
                'mac_address' => $mac_address,
                'auth_id' => $authIndex,
                'domain' => '',
                'username' => self::decode_string($data['agentAuthMgrClientUserName']),
                'ip_address' => '',
                'host_mode' => $data['agentAuthMgrPortHostMode'],
                'authz_status' => $data['agentAuthMgrClientAuthstatus'],
                'authz_by' => $data['agentAuthMgrClientAuthVlanAssignedReason'] ?? 'unknown',
                'timeout' => $data['agentAuthMgrClientSessionTimeout'],
                'vlan' => $data['agentAuthMgrClientVlanAssigned'] ?? 'unknown',
                'authc_status' => $data['agentAuthMgrClientAuthState'],
                'method' => $data['agentAuthMgrClientAuthMethod'] ?? '',
                'time_elapsed' => $data['agentAuthMgrClientSessionTime'],
            ]));
        }

        return $nac;
    }
}
