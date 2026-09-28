<?php

namespace App\Http\Controllers;

use App\Actions\ManageCustomers;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ManageCustomers $customers)
    {
        return view('dashboard', [
            'customerCount' => $customers->query($request->user())->count(),
            'recentCustomers' => $customers->query($request->user())->latest()->orderByDesc('id')->limit(5)->get(),
        ]);
    }
}
