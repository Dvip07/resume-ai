<?php

namespace App\Console\Commands;

use App\Http\Controllers\apps\POManage;
use Illuminate\Console\Command;

class getDeniMaxPO extends Command
{
  /**
   * The name and signature of the console command.
   *
   * @var string
   */
  protected $signature = 'getDeniMaxPO:daily';

  /**
   * The console command description.
   *
   * @var string
   */
  protected $description = 'get daily denimax po details';

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
    $poManage = new POManage();
    $poManage->getDeniMaxPo();
    return true;
  }
}
