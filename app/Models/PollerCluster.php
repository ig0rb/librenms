<?php

/**
 * PollerCluster.php
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
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * @link       https://www.librenms.org
 *
 * @copyright  2020 Thomas Berberich
 * @author     Thomas Berberich <sourcehhdoctor@gmail.com>
 */

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LibreNMS\Exceptions\InvalidNameException;

class PollerCluster extends Model
{
    public $timestamps = false;
    protected $table = 'poller_cluster';
    protected $primaryKey = 'id';
    protected $fillable = ['poller_name'];

    /**
     * @return array{last_report: 'datetime'}
     */
    protected function casts(): array
    {
        return [
            'last_report' => 'datetime',
        ];
    }

    // ---- Accessors/Mutators ----

    /**
     * @param  array|string  $groups
     * @return void
     */
    public function setPollerGroupsAttribute($groups): void
    {
        $this->attributes['poller_groups'] = is_array($groups) ? implode(',', $groups) : $groups;
    }

    // ---- Scopes ----

    public function scopeIsActive(Builder $query): Builder
    {
        $default = (int) \LibreNMS\Config::get('service_poller_frequency');

        return $query->where('last_report', '>=', \DB::raw("DATE_SUB(NOW(),INTERVAL COALESCE(`poller_frequency`, $default) SECOND)"));
    }

    public function scopeIsInactive(Builder $query): Builder
    {
        $default = (int) \LibreNMS\Config::get('service_poller_frequency');

        return $query->where('last_report', '<', \DB::raw("DATE_SUB(NOW(),INTERVAL COALESCE(`poller_frequency`, $default) SECOND)"));
    }

    // ---- Helpers ----

    /**
     * Get the value of a setting (falls back to the global value if not set on this node)
     *
     * @param  string  $name
     * @return mixed
     *
     * @throws InvalidNameException
     */
    public function getSettingValue(string $name)
    {
        $definition = $this->configDefinition(false);

        foreach ($definition as $entry) {
            if ($entry['name'] == $name) {
                return $entry['value'];
            }
        }

        throw new InvalidNameException("Poller group setting named \"$name\" is invalid");
    }

    /**
     * Get the frontend config definition for this poller
     *
     * @param  \Illuminate\Support\Collection|bool|null  $groups  optionally supply full list of poller groups to avoid fetching multiple times
     * @return array[]
     */
    public function configDefinition($groups = null)
    {
        if ($groups === null || $groups === true) {
            $groups = PollerGroup::list();
        }

        $scheduleType = \LibreNMS\Config::get('schedule_type');

        $pollerGloballyEnabled = $scheduleType['poller'] == 'legacy' ? \LibreNMS\Config::get('service_poller_enabled', true) : $scheduleType['poller'] == 'dispatcher';
        $discoveryGloballyEnabled = $scheduleType['discovery'] == 'legacy' ? \LibreNMS\Config::get('service_discovery_enabled', true) : $scheduleType['discovery'] == 'dispatcher';
        $servicesGloballyEnabled = $scheduleType['services'] == 'legacy' ? \LibreNMS\Config::get('service_services_enabled', true) : $scheduleType['services'] == 'dispatcher';
        $alertGloballyEnabled = $scheduleType['alerting'] == 'legacy' ? \LibreNMS\Config::get('service_alerting_enabled', true) : $scheduleType['alerting'] == 'dispatcher';
        $billingGloballyEnabled = $scheduleType['billing'] == 'legacy' ? \LibreNMS\Config::get('service_billing_enabled', true) : $scheduleType['billing'] == 'dispatcher';
        $pingGloballyEnabled = $scheduleType['ping'] == 'legacy' ? \LibreNMS\Config::get('service_ping_enabled', true) : $scheduleType['ping'] == 'dispatcher';

        return [
            [
                'name' => 'poller_groups',
                'default' => \LibreNMS\Config::get('distributed_poller_group'),
                'value' => $this->poller_groups ?? \LibreNMS\Config::get('distributed_poller_group'),
                'type' => 'multiple',
                'options' => $groups,
            ],
            [
                'name' => 'poller_enabled',
                'default' => $pollerGloballyEnabled,
                'value' => (bool) ($this->poller_enabled ?? $pollerGloballyEnabled),
                'type' => 'boolean',
            ],
            [
                'name' => 'poller_workers',
                'default' => \LibreNMS\Config::get('service_poller_workers'),
                'value' => $this->poller_workers ?? \LibreNMS\Config::get('service_poller_workers'),
                'type' => 'integer',
                'units' => 'workers',
            ],
            [
                'name' => 'poller_frequency',
                'default' => \LibreNMS\Config::get('service_poller_frequency'),
                'value' => $this->poller_frequency ?? \LibreNMS\Config::get('service_poller_frequency'),
                'type' => 'integer',
                'units' => 'seconds',
                'advanced' => true,
            ],
            [
                'name' => 'poller_down_retry',
                'default' => \LibreNMS\Config::get('service_poller_down_retry'),
                'value' => $this->poller_down_retry ?? \LibreNMS\Config::get('service_poller_down_retry'),
                'type' => 'integer',
                'units' => 'seconds',
            ],
            [
                'name' => 'discovery_enabled',
                'default' => $discoveryGloballyEnabled,
                'value' => (bool) ($this->discovery_enabled ?? $discoveryGloballyEnabled),
                'type' => 'boolean',
            ],
            [
                'name' => 'discovery_workers',
                'default' => \LibreNMS\Config::get('service_discovery_workers'),
                'value' => $this->discovery_workers ?? \LibreNMS\Config::get('service_discovery_workers'),
                'type' => 'integer',
                'units' => 'workers',
            ],
            [
                'name' => 'discovery_frequency',
                'default' => \LibreNMS\Config::get('service_discovery_frequency'),
                'value' => $this->discovery_frequency ?? \LibreNMS\Config::get('service_discovery_frequency'),
                'type' => 'integer',
                'units' => 'seconds',
                'advanced' => true,
            ],
            [
                'name' => 'services_enabled',
                'default' => $servicesGloballyEnabled,
                'value' => (bool) ($this->services_enabled ?? $servicesGloballyEnabled),
                'type' => 'boolean',
            ],
            [
                'name' => 'services_workers',
                'default' => \LibreNMS\Config::get('service_services_workers'),
                'value' => $this->services_workers ?? \LibreNMS\Config::get('service_services_workers'),
                'type' => 'integer',
                'units' => 'workers',
            ],
            [
                'name' => 'services_frequency',
                'default' => \LibreNMS\Config::get('service_services_frequency'),
                'value' => $this->services_frequency ?? \LibreNMS\Config::get('service_services_frequency'),
                'type' => 'integer',
                'units' => 'seconds',
                'advanced' => true,
            ],
            [
                'name' => 'billing_enabled',
                'default' => $billingGloballyEnabled,
                'value' => (bool) ($this->billing_enabled ?? $billingGloballyEnabled),
                'type' => 'boolean',
            ],
            [
                'name' => 'billing_frequency',
                'default' => \LibreNMS\Config::get('service_billing_frequency'),
                'value' => $this->billing_frequency ?? \LibreNMS\Config::get('service_billing_frequency'),
                'type' => 'integer',
                'units' => 'seconds',
                'advanced' => true,
            ],
            [
                'name' => 'billing_calculate_frequency',
                'default' => \LibreNMS\Config::get('service_billing_calculate_frequency'),
                'value' => $this->billing_calculate_frequency ?? \LibreNMS\Config::get('service_billing_calculate_frequency'),
                'type' => 'integer',
                'units' => 'seconds',
                'advanced' => true,
            ],
            [
                'name' => 'alerting_enabled',
                'default' => $alertGloballyEnabled,
                'value' => (bool) ($this->alerting_enabled ?? $alertGloballyEnabled),
                'type' => 'boolean',
            ],
            [
                'name' => 'alerting_frequency',
                'default' => \LibreNMS\Config::get('service_alerting_frequency'),
                'value' => $this->alerting_frequency ?? \LibreNMS\Config::get('service_alerting_frequency'),
                'type' => 'integer',
                'units' => 'seconds',
                'advanced' => true,
            ],
            [
                'name' => 'ping_enabled',
                'default' => $pingGloballyEnabled,
                'value' => (bool) ($this->ping_enabled ?? $pingGloballyEnabled),
                'type' => 'boolean',
            ],
            [
                'name' => 'ping_frequency',
                'default' => \LibreNMS\Config::get('ping_rrd_step'),
                'value' => $this->ping_frequency ?? \LibreNMS\Config::get('ping_rrd_step'),
                'type' => 'integer',
                'units' => 'seconds',
                'advanced' => true,
            ],
            [
                'name' => 'update_enabled',
                'default' => \LibreNMS\Config::get('service_update_enabled'),
                'value' => (bool) ($this->update_enabled ?? \LibreNMS\Config::get('service_update_enabled')),
                'type' => 'boolean',
                'advanced' => true,
            ],
            [
                'name' => 'update_frequency',
                'default' => \LibreNMS\Config::get('service_update_frequency'),
                'value' => $this->update_frequency ?? \LibreNMS\Config::get('service_update_frequency'),
                'type' => 'integer',
                'units' => 'seconds',
                'advanced' => true,
            ],
            [
                'name' => 'loglevel',
                'default' => \LibreNMS\Config::get('service_loglevel'),
                'value' => $this->loglevel ?? \LibreNMS\Config::get('service_loglevel'),
                'type' => 'select',
                'options' => [
                    'DEBUG' => 'DEBUG',
                    'INFO' => 'INFO',
                    'WARNING' => 'WARNING',
                    'ERROR' => 'ERROR',
                    'CRITICAL' => 'CRITICAL',
                ],
            ],
            [
                'name' => 'watchdog_enabled',
                'default' => \LibreNMS\Config::get('service_watchdog_enabled'),
                'value' => (bool) ($this->watchdog_enabled ?? \LibreNMS\Config::get('service_watchdog_enabled')),
                'type' => 'boolean',
            ],
            [
                'name' => 'watchdog_log',
                'default' => \LibreNMS\Config::get('log_file'),
                'value' => $this->watchdog_log ?? \LibreNMS\Config::get('log_file'),
                'type' => 'text',
                'advanced' => true,
            ],
        ];
    }

    // ---- Relationships ----
    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\PollerClusterStat, $this>
     */
    public function stats(): HasMany
    {
        return $this->hasMany(PollerClusterStat::class, 'parent_poller', 'id');
    }
}
