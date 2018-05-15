<?php
namespace App\Http\Controllers;

use App\Components\Yzy;
use App\Http\Models\Coupon;
use App\Http\Models\Goods;
use App\Http\Models\Order;
use App\Http\Models\Payment;
use App\Http\Models\PaymentCallback;
use Illuminate\Http\Request;
use Response;
use Redirect;
use Log;
use DB;

class PaymentController extends Controller
{
    protected static $config;

    function __construct()
    {
        self::$config = $this->systemConfig();
    }

    // 创建支付单
    public function create(Request $request)
    {

        $order_id = intval($request->get('order_id'));
        if (!empty($order_id)) {

            // 判断是否存在同个商品的未支付订单
            $order = Order::query()->where('order', $order_id)->first();
            if ($order) {
                return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：未找到支付订单']);
            }
            $goods = Goods::query()->where('id', $goods_id)->where('status', 1)->first();
            if (!$goods) {
                return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：商品或服务已下架']);
            }

            // 从网页传入 price 
            $price = $goods->price;
            // 从网页传入 type [1: 微信, 2: 支付宝]
            $type = 1;
            // 填写 api_user
            $api_user = self::$config['youzan_client_id'];
            // 填写 api_key 
            $api_key = self::$config['youzan_client_secret'];
            // 您系统内部生成的订单号, 每创建一个订单, 此订单号需要+1
            $order_id = $order->oid;
            // 您自定义的用户信息, 方便在后台对账, 排查订单是由哪个用户发起的, 强烈建议加上
            $order_info = $order->user_id;
            // 用户支付成功之后, 跳转到的页面
            $redirect = self::$config['kdt_id'];

            // 签名 
            $signature = md5($api_key. $api_user. $order_id. $order_info. $price. $redirect. $type);
            return Response::json(['status' => 'success', 'data' => $ret, 'message' => '创建支付单成功']);
        }

        $goods_id = intval($request->get('goods_id'));
        $coupon_sn = $request->get('coupon_sn');
        $user = $request->session()->get('user');

        $goods = Goods::query()->where('id', $goods_id)->where('status', 1)->first();
        if (!$goods) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：商品或服务已下架']);
        }

        // 判断是否开启有赞云支付
        if (!self::$config['is_youzan']) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：系统并未开启在线支付功能']);
        }

        // 判断是否存在同个商品的未支付订单
        $existsOrder = Order::query()->where('goods_id', $goods_id)->where('status', 0)->where('user_id', $user['id'])->first();
        if ($existsOrder) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：尚有未支付的订单，请先去支付']);
        }

        // 使用优惠券
        if ($coupon_sn) {
            $coupon = Coupon::query()->where('sn', $coupon_sn)->whereIn('type', [1, 2])->where('is_del', 0)->where('status', 0)->first();
            if (!$coupon) {
                return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：优惠券不存在']);
            }

            // 计算实际应支付总价
            $amount = $coupon->type == 2 ? $goods->price * $coupon->discount : $goods->price - $coupon->amount;
            $amount = $amount > 0 ? $amount : 0;
        } else {
            $amount = $goods->price;
        }

        // 如果最后总价格为0，则不允许创建支付单
        if ($amount <= 0) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：合计价格为0，无需使用在线支付']);
        }

        DB::beginTransaction();
        try {
            $user = $request->session()->get('user');
            $orderSn = date('ymdHis') . mt_rand(100000, 999999);
            $sn = makeRandStr(12);

            // 生成订单
            $order = new Order();
            $order->order_sn = $orderSn;
            $order->user_id = $user['id'];
            $order->goods_id = $goods_id;
            $order->coupon_id = !empty($coupon) ? $coupon->id : 0;
            $order->origin_amount = $goods->price;
            $order->amount = $amount;
            $order->expire_at = date("Y-m-d H:i:s", strtotime("+" . $goods->days . " days"));
            $order->is_expire = 0;
            $order->pay_way = 2;
            $order->status = 0;
            $order->save();

            // 从网页传入 price 
            $price = $goods->price;
            // 从网页传入 type [1: 微信, 2: 支付宝]
            $type = 1;
            // 填写 api_user
            $api_user = self::$config['youzan_client_id'];
            // 填写 api_key 
            $api_key = self::$config['youzan_client_secret'];
            // 您系统内部生成的订单号, 每创建一个订单, 此订单号需要+1
            $order_id = $order->oid;
            // 您自定义的用户信息, 方便在后台对账, 排查订单是由哪个用户发起的, 强烈建议加上
            $order_info = $order->user_id;
            // 用户支付成功之后, 跳转到的页面
            $redirect = self::$config['kdt_id'];

            // 签名 
            $signature = md5($api_key. $api_user. $order_id. $order_info. $price. $redirect. $type);


            $payment = new Payment();
            $payment->sn = $sn;
            $payment->user_id = $user['id'];
            $payment->oid = $order->oid;
            $payment->order_sn = $orderSn;
            $payment->pay_way = 1;
            $payment->amount = $amount;
            //存放sign值
            $payment->qr_code = $signature;
            $payment->status = 0;
            $payment->save();

            DB::commit();

            $ret['api_user'] = $api_user;
            $ret['price'] = $price;
            $ret['type'] = $type;
            $ret['redirect'] = $redirect;
            $ret['order_id'] = $order_id;
            $ret['order_info'] =$order_info;
            $ret['signature'] = $signature;
            return Response::json(['status' => 'success', 'data' => $ret, 'message' => '创建支付单成功']);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('创建支付订单失败：' . $e->getMessage());

            return Response::json(['status' => 'fail', 'data' => '', 'message' => '创建支付单失败：' . $e->getMessage()]);
        }
    }

    // 支付单详情
    public function detail(Request $request, $sn)
    {
        if (empty($sn)) {
            return Redirect::to('user/goodsList');
        }

        $user = $request->session()->get('user');

        $payment = Payment::query()->with(['order', 'order.goods'])->where('sn', $sn)->where('user_id', $user['id'])->first();
        if (!$payment) {
            return Redirect::to('user/goodsList');
        }

        $order = Order::query()->where('oid', $payment->oid)->first();
        if (!$order) {
            $request->session()->flash('errorMsg', '订单不存在');

            return Response::view('payment/' . $sn);
        }

        $view['payment'] = $payment;
        $view['website_analytics'] = self::$config['website_analytics'];
        $view['website_customer_service'] = self::$config['website_customer_service'];

        return Response::view('payment/detail', $view);
    }

    // 获取订单支付状态
    public function getStatus(Request $request)
    {
        $sn = $request->get('sn');

        if (empty($sn)) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '请求失败']);
        }

        $user = $request->session()->get('user');
        $payment = Payment::query()->where('sn', $sn)->where('user_id', $user['id'])->first();
        if (!$payment) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '支付失败']);
        }

        if ($payment->status) {
            return Response::json(['status' => 'success', 'data' => '', 'message' => '支付成功']);
        } else if ($payment->status < 0) {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '支付失败']);
        } else {
            return Response::json(['status' => 'fail', 'data' => '', 'message' => '等待支付']);
        }
    }

    // 有赞云回调日志
    public function callbackList(Request $request)
    {
        $status = $request->get('status', 0);

        $query = PaymentCallback::query();

        if ($status) {
            $query->where('status', $status);
        }

        $view['list'] = $query->orderBy('id', 'desc')->paginate(10);

        return Response::view('payment/callbackList', $view);
    }
}