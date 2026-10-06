<?php

namespace App\Console\Commands;

use App\Http\Controllers\apps\GRNManage;
use App\Http\Controllers\apps\POManage;
use Illuminate\Console\Command;

class getDeniMaxGRN extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'getDeniMaxGRN:daily';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'get daily denimax grn details';

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
    $grnManage = new GRNManage();
    $grnManage->getGRNQtyByOrderInward();
    return true;
  }
}
