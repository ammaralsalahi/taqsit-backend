<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Installment; 
use App\Models\Order;
use App\Models\Merchant; 
use App\Models\User;     

class BankController extends Controller
{
    // دالة لوحة التحكم الرئيسية
    public function index()
    {
        $installments = Installment::with(['user', 'product.merchant'])->latest()->get();
        $merchants = Merchant::all();
        
        // الحسابات المالية التي يطلبها الـ Blade
        $total_financed = Installment::sum('amount');
        $total_paid = Installment::where('status', 'paid')->sum('amount');
        $total_pending = Installment::where('status', 'pending')->sum('amount');
        $late_payments_count = Installment::where('status', 'late')->count();

        return view('bank.dashboard', compact(
            'installments', 'merchants', 'total_financed', 
            'total_paid', 'total_pending', 'late_payments_count'
        ));
    }

    // دالة صفحة التقارير الشاملة
    public function reports()
    {
        // جلب البيانات اللازمة لجدول التقارير والفلترة
        $installments = Installment::with(['user', 'product.merchant'])->latest()->get();
        $merchants = Merchant::all();

        return view('bank.reports', compact('installments', 'merchants'));
    }
}