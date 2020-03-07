<?php
/**
 *
 * @author: xaboy<365615158@qq.com>
 * @day: 2017/12/21
 */

namespace app\routine\model\user;

use basic\ModelBasic;
use service\SystemConfigService;
use think\Request;
use think\Session;
use think\Log;
use traits\ModelTrait;

/**
 * 用户model
 * Class User
 * @package app\routine\model\user
 */
class User extends ModelBasic
{
    use ModelTrait;

    public static function updateWechatUser($wechatUser,$uid)
    {
        return self::edit([
            'nickname'=>$wechatUser['nickname']?:'',
            'avatar'=>$wechatUser['headimgurl']?:'',
			'spread_uid'=>$wechatUser['spid']?:0,
            'last_time'=>time(),
            'last_ip'=>Request::instance()->ip(),
        ],$uid,'uid');
    }



    /**
     * 小程序用户添加
     * @param $routineUser
     * @param int $spread_uid
     * @return object
     */
    public static function setRoutineUser($routineUser,$spread_uid = 0){
        return self::set([
            'account'=>'rt'.$routineUser['uid'].time(),
            'pwd'=>md5(123456),
            'nickname'=>$routineUser['nickname']?:'',
            'avatar'=>$routineUser['headimgurl']?:'',
            'spread_uid'=>$spread_uid,
            'uid'=>$routineUser['uid'],
            'add_time'=>$routineUser['add_time'],
            'add_ip'=>Request::instance()->ip(),
            'last_time'=>time(),
            'last_ip'=>Request::instance()->ip(),
            'user_type'=>$routineUser['user_type']
        ]);
    }

    /**
     * 获得当前登陆用户UID
     * @return int $uid
     */
    public static function getActiveUid()
    {
        $uid = null;
        $uid = Session::get('LoginUid');
        if($uid) return $uid;
        else return 0;
    }
    public static function getUserInfo($uid)
    {
        $userInfo = self::where('uid',$uid)->find();
        if(!$userInfo) exception('读取用户信息失败!');
        return $userInfo->toArray();
    }

    /**
     * 判断当前用户是否推广员
     * @param int $uid
     * @return bool
     */
    public static function isUserSpread($uid = 0){
        if(!$uid) return false;
        $status = (int)SystemConfigService::get('store_brokerage_statu');
        $isPromoter = true;
        if($status == 1) $isPromoter = self::where('uid',$uid)->value('is_promoter');
        if($isPromoter) return true;
        else return false;
    }


    /**
     * 小程序用户一级分销
     * @param $orderInfo
     * @return bool
     */
    public static function backOrderBrokerage($orderInfo)
    {
        $userInfo = User::getUserInfo($orderInfo['uid']);
        if(!$userInfo || !$userInfo['spread_uid']) return true;
        $storeBrokerageStatu = SystemConfigService::get('store_brokerage_statu') ? : 1;//获取后台分销类型
        if($storeBrokerageStatu == 1){
            if(!User::be(['uid'=>$userInfo['spread_uid'],'is_promoter'=>1])) return true;
        }
        $brokerageSumRatio = (SystemConfigService::get('store_brokerage_total_ratio') ?: 0)/100;
        $brokerageRatio = (SystemConfigService::get('store_brokerage_ratio') ?: 0)/100;
        if($brokerageSumRatio <= 0 || $brokerageRatio <= 0) return true;
        //$cost = isset($orderInfo['cost']) ? $orderInfo['cost'] : 0;//成本价
        //if($cost > $orderInfo['pay_price']) return true;//成本价大于支付价格时直接返回
        $brokeragePrice = bcmul(bcmul($orderInfo['pay_price'],$brokerageSumRatio,2),$brokerageRatio,2);
        if($brokeragePrice <= 0) return true;
        $mark = $userInfo['nickname'].'成功消费'.floatval($orderInfo['pay_price']).'元,奖励推广佣金'.floatval($brokeragePrice);
        self::beginTrans();
        $res1 = UserBill::income('分销金',$userInfo['spread_uid'],'distribution_money','brokerage',$brokeragePrice,$orderInfo['id'],0,$mark, 1, $orderInfo['uid']);
        $res2 = self::bcInc($userInfo['spread_uid'],'distribution_money',$brokeragePrice,'uid');
        $res = $res1 && $res2;
        self::checkTrans($res);
        if($res) self::backOrderBrokerageTwo($orderInfo);
        return $res;
    }

    /**
     * 小程序 二级推广
     * @param $orderInfo
     * @return bool
     */
    public static function backOrderBrokerageTwo($orderInfo){
        $userInfo = User::getUserInfo($orderInfo['uid']);
        $userInfoTwo = User::getUserInfo($userInfo['spread_uid']);
        if(!$userInfoTwo || !$userInfoTwo['spread_uid']) return true;
        $storeBrokerageStatu = SystemConfigService::get('store_brokerage_statu') ? : 1;//获取后台分销类型
        if($storeBrokerageStatu == 1){
            if(!User::be(['uid'=>$userInfoTwo['spread_uid'],'is_promoter'=>1]))  return true;
        }
        $brokerageSumRatio = (SystemConfigService::get('store_brokerage_total_ratio') ?: 0)/100;
        $brokerageRatio = (SystemConfigService::get('store_brokerage_two') ?: 0)/100;
        if($brokerageSumRatio <= 0 || $brokerageRatio <= 0) return true;
        //$cost = isset($orderInfo['cost']) ? $orderInfo['cost'] : 0;//成本价
        //if($cost > $orderInfo['pay_price']) return true;//成本价大于支付价格时直接返回
        $brokeragePrice = bcmul(bcmul($orderInfo['pay_price'],$brokerageSumRatio,2),$brokerageRatio,2);
        if($brokeragePrice <= 0) return true;
        $mark = '二级推广人'.$userInfo['nickname'].'成功消费'.floatval($orderInfo['pay_price']).'元,奖励推广佣金'.floatval($brokeragePrice);
        self::beginTrans();
        $res1 = UserBill::income('分销金',$userInfoTwo['spread_uid'],'distribution_money','brokerage',$brokeragePrice,$orderInfo['id'],0,$mark, 1, $orderInfo['uid']);
        $res2 = self::bcInc($userInfoTwo['spread_uid'],'distribution_money',$brokeragePrice,'uid');
        $res = $res1 && $res2;
        self::checkTrans($res);
        return $res;
    }
	public static function getVipLevelConfig()
	{
		$vipLevelConfig = SystemConfigService::get('vip_level_config');
		$vipLevelConfig = str_replace("\r\n","\n",$vipLevelConfig);
		$levelConfig = Array();
		$vipLevelConfigList = explode("\n", $vipLevelConfig);
		foreach($vipLevelConfigList as $configDetail)
		{
			$configDetail = explode(',', $configDetail);
			if(count($configDetail) != 4)
				continue;
			$ruleList = explode('|', $configDetail[2]);
			$subLevelConfig = Array();
			$levelId = $configDetail[0];
			$subLevelConfig['vip_name'] = $configDetail[1];
			$subLevelConfig['discount'] = $configDetail[3];
			$subLevelConfig['rule'] = Array();
			foreach($ruleList as $rule)
			{
				$opt = '>';
				if(strpos($rule, '>=') != false) $opt = '>=';
				if(strpos($rule, '==') != false) $opt = '==';
				$items = explode($opt, $rule);
				if(count($items) == 2)
				{
					$subLevelConfig['rule'][$items[0]] = ['value'=> $items[1], 'opt'=> $opt];
				}
			}
			$levelConfig[$levelId] = $subLevelConfig;
		}
		return $levelConfig;
	}

	public static function updateVipLevel($uid, $event, $value)
	{
        $userInfo = User::getUserInfo($uid);
		$levelConfig = getVipLevelConfig();
		$maxLevelId = 0;
		foreach($levelConfig as $levelId => $config)
		{
			if(array_key_exists($event, $config['rule']))
			{
				if($config['rule'][$event]['opt'] == '>' && $value <= $config['rule'][$event]['value']) break;
				if($config['rule'][$event]['opt'] == '>=' && $value < $config['rule'][$event]['value']) break;
				if($config['rule'][$event]['opt'] == '==' && $value != $config['rule'][$event]['value']) break;

				$maxLevelId = $levelId;
			}
		}
		if($userInfo['level'] >= $maxLevelId) return true;
		return User::where('uid', $uid)->update(['level'=> $maxLevelId]);
	}
}
