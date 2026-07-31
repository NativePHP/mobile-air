<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

trait CreatesAndroidCredentials
{
    public function generateAndroidCredentials(): void
    {
        $this->info('🤖 Creating Android keystore (JKS file)...');

        // Ensure credentials directory exists
        $credentialsDir = base_path('credentials');
        if (! is_dir($credentialsDir)) {
            mkdir($credentialsDir, 0755, true);
            $this->info('📁 Created credentials directory');
            $this->addCredentialsToGitignore();
        }

        // Check if keytool is available and get the command
        $keytoolCommand = $this->getKeytoolCommand();
        if (! $keytoolCommand) {
            $this->error('❌ keytool is not available. Please install Java Development Kit (JDK).');

            return;
        }

        // Collect keystore information
        $keystoreName = text(
            label: 'Keystore filename',
            default: 'app-release-key.jks',
            validate: fn (string $value) => match (true) {
                strlen($value) < 1 => 'Filename is required.',
                ! str_ends_with($value, '.jks') => 'Filename must end with .jks',
                default => null
            }
        );

        $keystorePath = $credentialsDir.DIRECTORY_SEPARATOR.$keystoreName;

        if (file_exists($keystorePath)) {
            if (! confirm('Keystore file already exists. Overwrite?', false)) {
                $this->warn('⚠️ Skipping Android keystore creation.');

                return;
            }
        }

        $alias = text(
            label: 'Key alias',
            default: 'app-key',
            validate: fn (string $value) => match (true) {
                strlen($value) < 1 => 'Alias is required.',
                default => null
            }
        );

        $password = password(
            label: 'Create your keystore password',
            validate: fn (string $value) => match (true) {
                strlen($value) < 6 => 'Password must be at least 6 characters.',
                default => null
            }
        );

        $confirmPassword = password('Confirm keystore password');

        if ($password !== $confirmPassword) {
            $this->error('❌ Passwords do not match.');

            return;
        }

        $keyPassword = password(
            label: 'Create a key password (press enter to use same as keystore password)'
        );

        if (empty($keyPassword)) {
            $keyPassword = $password;
        }

        // Collect certificate information
        $this->info('📋 Certificate information:');

        $commonName = text(
            label: 'Your name (CN) - optional',
            default: ''
        );

        $organizationalUnit = text(
            label: 'Organizational unit (OU) - optional',
            default: ''
        );

        $organization = text(
            label: 'Organization (O) - optional',
            default: ''
        );

        $city = text(
            label: 'City (L) - optional',
            default: ''
        );

        $state = text(
            label: 'State/Province (ST) - optional',
            default: ''
        );

        $country = text(
            label: 'Country code (C) - 2 letters, optional',
            default: '',
            validate: fn (string $value) => match (true) {
                strlen($value) === 0 => null,
                strlen($value) !== 2 => 'Country code must be 2 letters if provided.',
                default => null
            }
        );

        $validity = text(
            label: 'Certificate validity (years)',
            default: '25',
            validate: fn (string $value) => match (true) {
                ! is_numeric($value) => 'Validity must be a number.',
                (int) $value < 1 => 'Validity must be at least 1 year.',
                default => null
            }
        );

        // Build the keytool command
        $dname = "CN={$commonName}, OU={$organizationalUnit}, O={$organization}, L={$city}, ST={$state}, C=".strtoupper($country);
        $validityDays = (int) $validity * 365;

        $command = [
            $keytoolCommand,
            '-genkeypair',
            '-keystore', $keystorePath,
            '-alias', $alias,
            '-keyalg', 'RSA',
            '-keysize', '2048',
            '-validity', (string) $validityDays,
            '-dname', $dname,
            '-storepass', $password,
            '-keypass', $keyPassword,
            '-storetype', 'JKS',
        ];

        // Execute keytool command
        $result = Process::run($command);

        if ($result->failed()) {
            $this->error('❌ Failed to create keystore: '.$result->errorOutput());

            return;
        }

        $this->info('✅ Android keystore created successfully!');
        $this->info("📁 Location: {$keystorePath}");
        $this->info("🔑 Alias: {$alias}");

        // Update environment variables
        $this->updateAndroidEnvVars($keystoreName, $password, $alias, $keyPassword);

        $this->newLine();
        $this->warn('⚠️ Important: Keep your keystore and passwords safe!');
        $this->line('Store these details securely:');
        $this->line("- Keystore: {$keystoreName}");
        $this->line("- Alias: {$alias}");
        $this->line('- Keystore password: [HIDDEN]');
        $this->line('- Key password: [HIDDEN]');
    }

    public function generateAndroidKeystoreReset(): void
    {
        $this->info('🔄 Creating new Android keystore for Google Play Console reset...');

        // Ensure credentials directory exists
        $credentialsDir = base_path('credentials');
        if (! is_dir($credentialsDir)) {
            mkdir($credentialsDir, 0755, true);
            $this->info('📁 Created credentials directory');
            $this->addCredentialsToGitignore();
        }

        // Check if keytool is available
        $keytoolCommand = $this->getKeytoolCommand();
        if (! $keytoolCommand) {
            $this->error('❌ keytool is not available. Please install Java Development Kit (JDK).');

            return;
        }

        // Collect keystore information
        $keystoreName = text(
            label: 'Keystore filename',
            default: 'upload-keystore.jks',
            validate: fn (string $value) => match (true) {
                strlen($value) < 1 => 'Filename is required.',
                ! str_ends_with($value, '.jks') => 'Filename must end with .jks',
                default => null
            }
        );

        $keystorePath = $credentialsDir.DIRECTORY_SEPARATOR.$keystoreName;

        $alias = text(
            label: 'Key alias',
            default: 'upload',
            validate: fn (string $value) => match (true) {
                strlen($value) < 1 => 'Alias is required.',
                default => null
            }
        );

        $pemFileName = pathinfo($keystoreName, PATHINFO_FILENAME).'_certificate.pem';
        $pemPath = $credentialsDir.DIRECTORY_SEPARATOR.$pemFileName;

        if (file_exists($keystorePath)) {
            if (! confirm('Upload keystore already exists. Overwrite?', false)) {
                $this->warn('⚠️ Skipping keystore reset.');

                return;
            }
        }

        $password = password(
            label: 'Keystore password',
            validate: fn (string $value) => match (true) {
                strlen($value) < 6 => 'Password must be at least 6 characters.',
                default => null
            }
        );

        $confirmPassword = password('Confirm keystore password');

        if ($password !== $confirmPassword) {
            $this->error('❌ Passwords do not match.');

            return;
        }

        // Use same password for key password (standard for upload keystores)
        $keyPassword = $password;

        // Collect certificate information
        $this->info('📋 Certificate information:');

        $commonName = text(
            label: 'Your name (CN) - optional',
            default: ''
        );

        $organizationalUnit = text(
            label: 'Organizational unit (OU) - optional',
            default: ''
        );

        $organization = text(
            label: 'Organization (O) - optional',
            default: ''
        );

        $city = text(
            label: 'City (L) - optional',
            default: ''
        );

        $state = text(
            label: 'State/Province (ST) - optional',
            default: ''
        );

        $country = text(
            label: 'Country code (C) - 2 letters, optional',
            default: '',
            validate: fn (string $value) => match (true) {
                strlen($value) === 0 => null,
                strlen($value) !== 2 => 'Country code must be 2 letters if provided.',
                default => null
            }
        );

        // Build the keytool command for keystore creation
        $dname = "CN={$commonName}, OU={$organizationalUnit}, O={$organization}, L={$city}, ST={$state}, C=".strtoupper($country);
        $validityDays = 25 * 365; // 25 years

        $command = [
            $keytoolCommand,
            '-genkeypair',
            '-keystore', $keystorePath,
            '-alias', $alias,
            '-keyalg', 'RSA',
            '-keysize', '2048',
            '-validity', (string) $validityDays,
            '-dname', $dname,
            '-storepass', $password,
            '-keypass', $keyPassword,
            '-storetype', 'JKS',
        ];

        // Execute keytool command to create keystore
        $result = Process::run($command);

        if ($result->failed()) {
            $this->error('❌ Failed to create keystore: '.$result->errorOutput());

            return;
        }

        $this->info('✅ Upload keystore created successfully!');

        // Export certificate as PEM file
        $this->info('📄 Exporting certificate as PEM file...');

        $exportCommand = [
            $keytoolCommand,
            '-export',
            '-rfc',
            '-keystore', $keystorePath,
            '-alias', $alias,
            '-file', $pemPath,
            '-storepass', $password,
        ];

        $exportResult = Process::run($exportCommand);

        if ($exportResult->failed()) {
            $this->error('❌ Failed to export certificate: '.$exportResult->errorOutput());

            return;
        }

        $this->info('✅ Certificate exported as PEM file!');

        // Update environment variables
        $this->updateAndroidEnvVars($keystoreName, $password, $alias, $keyPassword);

        $this->newLine();
        $this->info('🎉 Google Play Console reset files created successfully!');
        $this->info('📁 Files created:');
        $this->line("  - Keystore: {$keystorePath}");
        $this->line("  - PEM Certificate: {$pemPath}");

        $this->newLine();
        $this->warn('📋 Next steps for Google Play Console:');
        $this->line('1. Go to Google Play Console → Your App → Setup → App Signing');
        $this->line('2. Click "Reset upload key"');
        $this->line("3. Upload the PEM file: {$pemFileName}");
        $this->line('4. Use the new keystore for future app builds');

        $this->newLine();
        $this->warn('⚠️ Important: Keep your keystore and passwords safe!');
        $this->line("- Keystore: {$keystoreName}");
        $this->line("- Alias: {$alias}");
        $this->line('- Password: [HIDDEN]');
    }

    private function getKeytoolCommand(): ?string
    {
        // Try different potential keytool commands based on platform
        $commands = ['keytool'];

        // On Windows, keytool might be in different locations
        if (PHP_OS_FAMILY === 'Windows') {
            $commands[] = 'keytool.exe';
            // Try common Java installation paths
            $javaHome = getenv('JAVA_HOME');
            if ($javaHome) {
                $commands[] = $javaHome.'\bin\keytool.exe';
            }
        }

        foreach ($commands as $command) {
            $result = Process::run([$command, '-help']);
            if ($result->exitCode() === 0) {
                return $command;
            }
        }

        return null;
    }

    private function updateAndroidEnvVars(string $keystoreFile, string $keystorePassword, string $keyAlias, string $keyPassword): void
    {
        $envPath = base_path('.env');
        $envVars = [
            'ANDROID_KEYSTORE_FILE' => $keystoreFile,
            'ANDROID_KEYSTORE_PASSWORD' => $keystorePassword,
            'ANDROID_KEY_ALIAS' => $keyAlias,
            'ANDROID_KEY_PASSWORD' => $keyPassword,
        ];

        $envContent = '';
        $existingVars = [];

        // Read existing .env file if it exists
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);

            // Check which variables already exist
            foreach ($envVars as $key => $value) {
                if (preg_match("/^{$key}=/m", $envContent)) {
                    $existingVars[] = $key;
                }
            }
        }

        // Add missing environment variables
        $addedVars = [];
        foreach ($envVars as $key => $value) {
            if (! in_array($key, $existingVars)) {
                if (! str_ends_with($envContent, PHP_EOL) && ! empty($envContent)) {
                    $envContent .= PHP_EOL;
                }
                if (empty($addedVars)) {
                    $envContent .= PHP_EOL.'# Android Keystore Configuration'.PHP_EOL;
                }
                $envContent .= "{$key}={$value}".PHP_EOL;
                $addedVars[] = $key;
            }
        }

        // Write updated .env file
        if (! empty($addedVars)) {
            file_put_contents($envPath, $envContent);
            $this->info('📝 Added Android keystore variables to .env:');
            foreach ($addedVars as $var) {
                $this->line("  - {$var}");
            }
        }

        // Inform about existing variables
        if (! empty($existingVars)) {
            $this->info('ℹ️  The following variables already exist in .env (not updated):');
            foreach ($existingVars as $var) {
                $this->line("  - {$var}");
            }
        }
    }

    private function addCredentialsToGitignore(): void
    {
        $gitignorePath = base_path('.gitignore');

        if (file_exists($gitignorePath)) {
            $gitignoreContent = file_get_contents($gitignorePath);

            // Check if credentials folder is already in .gitignore
            if (! str_contains($gitignoreContent, '/credentials/') && ! str_contains($gitignoreContent, 'credentials/')) {
                // Add credentials folder to .gitignore
                $newContent = $gitignoreContent.PHP_EOL.'# Credential files (keystores, private keys, etc.)'.PHP_EOL.'/credentials/'.PHP_EOL;
                file_put_contents($gitignorePath, $newContent);
                $this->info('📝 Added /credentials/ to .gitignore');
            }
        } else {
            // Create .gitignore if it doesn't exist
            $gitignoreContent = '# Credential files (keystores, private keys, etc.)'.PHP_EOL.'/credentials/'.PHP_EOL;
            file_put_contents($gitignorePath, $gitignoreContent);
            $this->info('📝 Created .gitignore with /credentials/');
        }
    }
}
