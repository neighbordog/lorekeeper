<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App;
use Carbon\Carbon;
use App\Services\UserService;
use App\Models\User\User;
use App\Models\User\UserAlias;
use App\Models\Rank\Rank;

class SetupAdminUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'setup-admin-user';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates the admin user account if no users exist, or resets the password if it does.';

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
        $this->info('********************');
        $this->info('* ADMIN USER SETUP *');
        $this->info('********************'."\n");

        // Retrieve environment variables
        $name = env('ADMIN_USERNAME', 'Admin');
        $email = env('ADMIN_EMAIL', null);
        $password = env('ADMIN_PASSWORD', null);
        $alias = env('ADMIN_ALIAS', null);

        // Check if required environment variables are set
        if(!$email || !$password) {
            $this->error('ADMIN_EMAIL and ADMIN_PASSWORD environment variables must be set.');
            return;
        }

        // First things first, check if user ranks exist...
        if(!Rank::count()) {

            // These need to be created even if the seeder isn't run for the site to work correctly.
            $adminRank = Rank::create([
                'name' => 'Admin',
                'description' => 'The site admin. Has the ability to view/edit any data on the site.',
                'sort' => 1
            ]);
            Rank::create([
                'name' => 'Member',
                'description' => 'A regular member of the site.',
                'sort' => 0
            ]);

            $this->line("User ranks not found. Default user ranks (admin and basic member) created.");
        }
        // Otherwise, grab the rank with the highest "sort" value. (This is the admin rank.)
        else {
            $adminRank = Rank::orderBy('sort', 'DESC')->first();
        }

        // Check if the admin user exists...
        $user = User::where('rank_id', $adminRank->id)->first();
        if(!$user) {

            $this->line('Setting up admin account. This account will have access to all site data, please make sure to keep the email and password secret!');

            // If environment is local/testing, handle email verification and alias
            $verifiedAt = Carbon::now();

            $service = new UserService;
            $user = $service->createUser([
                'name' => $name,
                'email' => $email,
                'rank_id' => $adminRank->id,
                'password' => $password,
                'dob' => [
                    'day' => '01',
                    'month' => '01',
                    'year' => '1970'
                ],
                'has_alias' => $alias ? 1 : 0
            ]);

            if($verifiedAt) {
                $user->email_verified_at = $verifiedAt;
                $user->save();
            }

            if($alias) {
                UserAlias::create([
                    'user_id' => $user->id,
                    'site' => 'deviantart',
                    'alias' => $alias,
                    'is_primary_alias' => 1,
                    'is_visible' => 1
                ]);
            }

            $this->line('Admin account created. You can now log in with the registered email and password.');
            $this->line('If necessary, you can run this command again to change the email address and password of the admin account.');
            return;
        }
        else {
            // Change the admin email/password if the user exists
            $this->line('Admin account [' . $user->name . '] already exists.');
            if(env('ADMIN_RESET', false)) {
                $this->line("Resetting email address and password for this account.");

                $service = new UserService;
                $service->updateUser([
                    'id' => $user->id,
                    'email' => $email,
                    'password' => $password
                ]);

                $this->line('Admin account email and password changed.');

                // Handle alias and email verification if in local environment
                if(App::environment('local')) {
                    if(env('APP_ENV_LOCAL_SETUP', false)) {
                        if($alias && !$user->has_alias) {
                            $this->line('Adding user alias...');
                            $user->update(['has_alias' => 1]);
                            UserAlias::create([
                                'user_id' => $user->id,
                                'site' => 'deviantart',
                                'alias' => $alias,
                                'is_primary_alias' => 1,
                                'is_visible' => 1
                            ]);
                        }
                        $this->line('Marking email address as verified...');
                        $user->email_verified_at = Carbon::now();
                        $user->save();
                    }
                }

                $this->line('Updates complete.');
            } else {
                $this->line('No changes made to existing admin account.');
            }
        }

        $this->line('Action completed.');
    }
}
