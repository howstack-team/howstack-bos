<?php

declare(strict_types=1);

namespace app\modules\compute\domain;

/** 云服务器配置资源的固定业务种类，不能由数据库任意扩展字段。 */
final class ComputeResources
{
    /** 当前可维护资源；旧网络策略仅供历史快照和草稿读取。 */
    public const array EDITABLE_KINDS = ['regions', 'zones', 'lines', 'flavor_groups', 'flavors', 'image_types', 'images', 'disk_types', 'protections'];

    public const array KINDS = ['regions', 'zones', 'lines', 'flavor_groups', 'flavors', 'image_types', 'images', 'disk_types', 'protections', 'network_policies'];
    public const array CYCLES = ['hour', 'day', 'month', 'year'];
}
