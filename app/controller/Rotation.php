<?php
declare (strict_types = 1);

namespace app\controller;

use app\service\MapRotation;

/**
 * 今天轮换地图（v3.8.1）
 * 规则：管理员维护"每日组合"（一组多张地图，从地图池多选），
 *       每天 9:00 切换到下一个组合循环轮换；游戏时间 9:00-24:00，
 *       凌晨 0 点到 9 点为未开始状态（预告今天的组合）
 * v5.1.0：配置收归入驻平台超管统一管理（数据落 tenant_id=0），全部战队共用一套
 * 全局开关：平台设置 map_rotation_enabled = 1 时功能才可用
 * 锚点：平台设置 map_rotation_start = 第 1 个组合对应的日期（YYYY-MM-DD）
 * v5.36.5：轮换推算抽到 MapRotation，网页与 QQ 机器人共用同一份快照
 */
class Rotation extends AuthBase
{
    public function index()
    {
        return api_ok(MapRotation::snapshot());
    }
}
