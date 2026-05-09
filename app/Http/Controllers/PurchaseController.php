<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\{User, Product, Order, Installment, Merchant, Bank, Transaction};
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseController extends Controller
{
    /**
     * معالجة عملية الشراء والتقسيط الاحترافية
     */
   public function purchase(Request $request)
    {
        // 1. التحقق من صحة البيانات الواردة
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'product_id' => 'required|exists:products,id',
            'installment_plan' => 'nullable|integer|min:1|max:12'
        ]);

        $user = User::find($request->user_id);
        $product = Product::find($request->product_id);

        // 2. الربط مع البنك عبر رقم الهاتف
        $bankAccount = Bank::where('phone_number', $user->phone_number)->first();

        if (!$bankAccount) {
            return response()->json(['status' => 'error', 'message' => 'عذراً، لا يوجد حساب بنكي مرتبط برقم الهاتف.'], 404);
        }

        // 3. فحص القيود
        if ($user->is_blocked || !$user->is_active) {
            return response()->json(['status' => 'error', 'message' => 'حسابك مقيد حالياً.'], 403);
        }

        // حساب الدفعة الأولى (50%) والمبلغ الممول
        $downPayment = $product->price * 0.50;
        $financedAmount = $product->price - $downPayment;

        // التحقق من سقف الائتمان مقابل المبلغ الممول
        if ($bankAccount->max_credit_limit < $financedAmount) {
            return response()->json(['status' => 'error', 'message' => 'المبلغ المطلوب تقسيطه يتجاوز سقف الائتمان المسموح به.'], 400);
        }

        // التحقق من الرصيد الكاش للدفعة الأولى
        if ($bankAccount->balance < $downPayment) {
            return response()->json(['status' => 'error', 'message' => 'رصيدك البنكي لا يكفي لخصم المقدم (50%).'], 400);
        }

        // 4. تنفيذ المعاملة المالية
        try {
            return DB::transaction(function () use ($user, $product, $downPayment, $financedAmount, $bankAccount, $request) {
                
                // أ- التحديث في جدول البنك
                $bankAccount->decrement('balance', $downPayment);
                $bankAccount->decrement('max_credit_limit', $financedAmount);

                // ب- تحويل المستحقات للتاجر بعد خصم العمولة 2%
                $commission = $product->price * 0.02;
                $netForMerchant = $product->price - $commission;
                
                $merchant = Merchant::find($product->merchant_id);
                if ($merchant) {
                    $merchant->increment('bank_balance', $netForMerchant);
                }

                // ج- تسجيل الطلب الرئيسي
                $order = Order::create([
                    'user_id' => $user->id,
                    'merchant_id' => $product->merchant_id,
                    'product_id' => $product->id,
                    'total_amount' => $product->price,
                    'down_payment' => $downPayment,
                    'remaining_amount' => $financedAmount,
                    'commission_amount' => $commission,
                    'status' => 'approved'
                ]);

                // د- تسجيل الحركة في سجل المعاملات (تم تعديل full_name هنا)
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'type' => 'purchase_down_payment',
                    'amount' => $downPayment,
                    'from_party' => 'حساب العميل: ' . $user->full_name, 
                    'to_party' => 'متجر التاجر: ' . ($merchant->store_name ?? 'التاجر'),
                ]);

                // هـ- جدولة الأقساط الشهرية
                $months = $request->input('installment_plan', 3); 
                $installmentAmount = $financedAmount / $months;

                for ($i = 1; $i <= $months; $i++) {
                    Installment::create([
                        'order_id' => $order->id,
                        'user_id'  => $user->id,
                        'amount'   => $installmentAmount,
                        'due_date' => Carbon::now()->addMonths($i),
                        'status'   => 'pending' 
                    ]);
                }

                return response()->json([
                    'status' => 'success',
                    'message' => 'تمت العملية بنجاح!',
                    'data' => [
                        'order_id' => $order->id,
                        'customer' => $user->full_name,
                        'installments' => $months,
                        'next_payment' => Carbon::now()->addMonth()->toDateString()
                    ]
                ], 200);
            });
        } catch (\Exception $e) {
            Log::error("Purchase Failed: " . $e->getMessage());
            return response()->json(['status' => 'error', 'message' => 'فشلت العملية: ' . $e->getMessage()], 500);
        }
    }
public function payInstallment(Request $request)
{
    // 1. التحقق من وجود رقم القسط في الطلب
    $request->validate([
        'installment_id' => 'required|exists:installments,id',
    ]);

    $currentUser = $request->user(); 
    $installment = Installment::find($request->installment_id);

    // 2. حماية: التأكد أن القسط يخص المستخدم الحالي
    if ($installment->user_id !== $currentUser->id) {
        return response()->json(['status' => 'error', 'message' => 'عذراً، هذا القسط لا يخص حسابك.'], 403);
    }

    // 3. منع سداد قسط مدفوع مسبقاً
    if ($installment->status === 'paid') {
        return response()->json(['status' => 'error', 'message' => 'هذا القسط مدفوع بالفعل.'], 400);
    }

    // 4. جلب حساب البنك (بناءً على رقم الهاتف)
    $bankAccount = Bank::where('phone_number', $currentUser->phone_number)->first();

    if (!$bankAccount || $bankAccount->balance < $installment->amount) {
        return response()->json(['status' => 'error', 'message' => 'عذراً، رصيدك البنكي لا يكفي لسداد القسط.'], 400);
    }

 try {
    return DB::transaction(function () use ($installment, $currentUser) {
        
        // 1. جلب الحساب مباشرة داخل الترانزاكشن لضمان عدم وجود Cache
        $bankAccount = Bank::where('phone_number', $currentUser->phone_number)->first();

        if (!$bankAccount) {
            throw new \Exception("لم يتم العثور على الحساب البنكي");
        }

        // 2. التحديث باستخدام Increments و Decrements (هذي الطريقة مستحيل تفشل إذا الـ balance نجح)
        $bankAccount->decrement('balance', $installment->amount);
        $bankAccount->increment('credit_score', 2);
        $bankAccount->increment('max_credit_limit', $installment->amount);

        // 3. تحديث حالة القسط
        $installment->update([
            'status' => 'paid',
            'paid_at' => now()
        ]);

        // 4. تسجيل العملية
        Transaction::create([
            'user_id' => $currentUser->id,
            'order_id' => $installment->order_id,
            'type' => 'installment_payment',
            'amount' => $installment->amount,
            'from_party' => 'حساب العميل: ' . $currentUser->full_name,
            'to_party' => 'البنك المركزي',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'تم التحديث بنجاح!',
            'debug_info' => [
                'new_balance' => $bankAccount->fresh()->balance,
                'new_score'   => $bankAccount->fresh()->credit_score
            ]
        ]);
    });
} catch (\Exception $e) {
    return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
}
}

    /**
     * جلب سجل الحركات المالية للمستخدم الحالي
     */
    public function getUserTransactions(Request $request)
    {
        $transactions = Transaction::where('user_id', $request->user()->id)
            ->with('order.product') 
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'count' => $transactions->count(),
            'data' => $transactions
        ], 200);
    }


    public function showBankDashboard()
{
    // جلب الإحصائيات التي طلبها النظام
    $stats = [
        'total_sales' => Order::sum('total_amount'),
        'overdue' => Installment::where('status', 'pending')->where('due_date', '<', now())->sum('amount'),
        'merchants_count' => Merchant::count(),
        'users_count' => User::count(),
    ];
    
    $recentTransactions = Transaction::with('user', 'order')->latest()->take(10)->get();

    return view('bank.dashboard', compact('stats', 'recentTransactions'));
}
}