<?php

/*
 * PortsController.php
 *
 * -Description-
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
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       http://librenms.org
 * @copyright  2021 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Http\Controllers\Table;

use App\Models\Port;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use LibreNMS\Util\Number;
use LibreNMS\Util\Rewrite;

class PortsController extends TableController
{
    protected function rules()
    {
        return [
            'device_id' => 'nullable|integer',
            'deleted' => 'boolean',
            'disabled' => 'boolean',
            'errors' => 'nullable|boolean',
            'hostname' => 'nullable|ip_or_hostname',
            'ifAlias' => 'nullable|string',
            'ifType' => 'nullable|string',
            'ifSpeed' => 'nullable|integer',
            'ignore' => 'boolean',
            'location' => 'nullable|integer',
            'port_descr_type' => 'nullable|string',
            'state' => 'nullable|in:up,down,admindown',
        ];
    }

    protected function filterFields($request)
    {
        return [
            'ports.device_id' => 'device_id',
            'location_id' => 'location',
            'ifSpeed',
            'ifType',
            'port_descr_type',
            'ports.disabled' => 'disabled',
            'ports.ignore' => 'ignore',
            'group' => function ($query, $group) {
                return $query->whereHas('groups', function ($query) use ($group) {
                    return $query->where('id', $group);
                });
            },
            'devicegroup' => function ($query, $devicegroup) {
                return $query->whereHas('device', function ($query) use ($devicegroup) {
                    return $query->whereHas('groups', function ($query) use ($devicegroup) {
                        return $query->where('id', $devicegroup);
                    });
                });
            },
        ];
    }

    protected function sortFields($request)
    {
        return [
            'hostname',
            'ifIndex',
            'ifDescr',
            'secondsIfLastChange',
            'ifConnectorPresent',
            'ifInErrors',
            'ifOutErrors',
            'ifInErrors_delta',
            'ifOutErrors_delta',
            'ifInOctets_rate',
            'ifOutOctets_rate',
            'ifInUcastPkts_rate',
            'ifOutUcastPkts_rate',
            'ifType',
            'ifAlias',
            'ifMtu',
            'ifSpeed',
        ];
    }

    protected function baseQuery($request)
    {
        $query = Port::hasAccess($request->user())
            ->with(['device', 'device.location'])
            ->leftJoin('devices', 'ports.device_id', 'devices.device_id')
            ->where('deleted', $request->get('deleted', 0)) // always filter deleted
            ->when($request->get('hostname'), function (Builder $query, $hostname) {
                $query->where(function (Builder $query) use ($hostname) {
                    $query->where('devices.hostname', 'like', "%$hostname%")
                        ->orWhere('devices.sysName', 'like', "%$hostname%");
                });
            })
            ->when($request->get('ifAlias'), function (Builder $query, $ifAlias) {
                return $query->where('ifAlias', 'like', "%$ifAlias%");
            })
            ->when($request->get('errors'), function (Builder $query) {
                return $query->hasErrors();
            })
            ->when($request->get('state'), function (Builder $query, $state) {
                return match ($state) {
                    'down' => $query->isDown(),
                    'up' => $query->isUp(),
                    'admindown' => $query->isShutdown(),
                    default => $query,
                };
            });

        $select = [
            'ports.*',
            'hostname',
        ];

        if (array_key_exists('secondsIfLastChange', Arr::wrap($request->get('sort')))) {
            // for sorting
            $select[] = DB::raw('`devices`.`uptime` - `ports`.`ifLastChange` / 100 as secondsIfLastChange');
        }

        return $query->select($select);
    }

    /**
     * @param  Port  $port
     * @return array
     */
    public function formatItem($port)
    {
        $status = $port->ifOperStatus == 'down'
            ? ($port->ifAdminStatus == 'up' ? 'label-danger' : 'label-warning')
            : 'label-success';

        return [
            'status' => $status,
            'device' => Blade::render('<x-device-link :device="$device" />', ['device' => $port->device]),
            'port' => Blade::render('<x-port-link :port="$port"/>', ['port' => $port]),
            'secondsIfLastChange' => ceil($port->device?->uptime - ($port->ifLastChange / 100)),
            'ifConnectorPresent' => ($port->ifConnectorPresent == 'true') ? 'yes' : 'no',
            'ifSpeed' => $port->ifSpeed,
            'ifMtu' => $port->ifMtu,
            'ifInOctets_rate' => $port->ifInOctets_rate * 8,
            'ifOutOctets_rate' => $port->ifOutOctets_rate * 8,
            'ifInUcastPkts_rate' => $port->ifInUcastPkts_rate,
            'ifOutUcastPkts_rate' => $port->ifOutUcastPkts_rate,
            'ifInErrors' => $port->ifInErrors,
            'ifOutErrors' => $port->ifOutErrors,
            'ifInErrors_delta' => $port->poll_period ? Number::formatSi($port->ifInErrors_delta / $port->poll_period, 2, 0, 'EPS') : '',
            'ifOutErrors_delta' => $port->poll_period ? Number::formatSi($port->ifOutErrors_delta / $port->poll_period, 2, 0, 'EPS') : '',
            'ifType' => Rewrite::normalizeIfType($port->ifType),
            'ifAlias' => htmlentities($port->ifAlias),
            'actions' => (string) view('port.actions', ['port' => $port]),
        ];
    }

    /**
     * Get headers for CSV export
     *
     * @return array
     */
    protected function getExportHeaders()
    {
        return [
            'Device ID',
            'Hostname',
            'Port',
            'ifIndex',
            'Status',
            'Admin Status',
            'Speed',
            'MTU',
            'Type',
            'In Rate (bps)',
            'Out Rate (bps)',
            'In Errors',
            'Out Errors',
            'In Error Rate',
            'Out Error Rate',
            'Description',
            'Last Change',
            'Connector Present',
        ];
    }

    /**
     * Format a row for CSV export
     *
     * @param  Port  $port
     * @return array
     */
    protected function formatExportRow($port)
    {
        $status = $port->ifOperStatus;
        $adminStatus = $port->ifAdminStatus;
        $speed = Number::formatSi($port->ifSpeed);

        return [
            'device_id' => $port->device_id,
            'hostname' => $port->device->displayName(),
            'port' => $port->ifName ?: $port->ifDescr,
            'ifindex' => $port->ifIndex,
            'status' => $status,
            'admin_status' => $adminStatus,
            'speed' => $speed,
            'mtu' => $port->ifMtu,
            'type' => Rewrite::normalizeIfType($port->ifType),
            'in_rate' => Number::formatBi($port->ifInOctets_rate * 8) . 'bps',
            'out_rate' => Number::formatBi($port->ifOutOctets_rate * 8) . 'bps',
            'in_errors' => $port->ifInErrors,
            'out_errors' => $port->ifOutErrors,
            'in_errors_rate' => $port->poll_period ? Number::formatSi($port->ifInErrors_delta / $port->poll_period, 2, 0, 'EPS') : '',
            'out_errors_rate' => $port->poll_period ? Number::formatSi($port->ifOutErrors_delta / $port->poll_period, 2, 0, 'EPS') : '',
            'description' => $port->ifAlias,
            'last_change' => $port->device ? ($port->device->uptime - ($port->ifLastChange / 100)) : 'N/A',
            'connector_present' => ($port->ifConnectorPresent == 'true') ? 'yes' : 'no',
        ];
    }
}
