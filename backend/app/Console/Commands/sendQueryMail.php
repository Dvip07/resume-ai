<?php

namespace App\Console\Commands;

use App\Http\Controllers\apps\InvoiceList;
use App\Http\Controllers\apps\POManage;
use Illuminate\Console\Command;

class sendQueryMail extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'sendQueryMail:daily';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'send daily mail for invoice query';

  /**
   * Create a new command instance.
   *
   * @return void
   */
  public function __construct()
  {
    parent::__construct();
  }

  /**
   * Execute the console command.
   *
   * @return mixed
   */
  public function handle()
  {
    $invoiceList = new InvoiceList();
    $invoiceList->invoiceQueryMail();
    return true;
  }
}
