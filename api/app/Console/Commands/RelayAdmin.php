<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RelayAdmin extends Command
{
    protected $signature = 'relay:admin
        {email : Admin email (created, or updated if it exists)}
        {--name=Admin : Display name}
        {--password= : Password (prompted for if omitted; at least 12 characters)}
        {--generate : Generate a random password and print it}';

    protected $description = 'Create or update the dashboard admin user (full access)';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        if (strcasecmp($email, (string) config('relay.demo.email')) === 0) {
            $this->error('That address is reserved for the demo user.');

            return self::FAILURE;
        }

        $password = $this->option('generate') ? Str::password(20) : ($this->option('password') ?? $this->secret('Password'));
        $validator = Validator::make(
            ['email' => $email, 'password' => $password],
            ['email' => ['required', 'email:rfc'], 'password' => ['required', 'string', 'min:12']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $existed = $user->exists;
        $user->fill(['name' => $this->option('name'), 'password' => $password]);
        $user->role = User::ROLE_ADMIN;
        $user->save();
        // Credentials changed: sign out everywhere.
        $user->sessions()->delete();

        $this->info(($existed ? 'Updated' : 'Created')." admin {$email}.");
        if ($this->option('generate')) {
            $this->line("Password: {$password}");
        }

        return self::SUCCESS;
    }
}
