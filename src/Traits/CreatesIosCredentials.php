<?php

namespace Native\Mobile\Traits;

use Illuminate\Support\Facades\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

trait CreatesIosCredentials
{
    public function generateIosCredentials(): void
    {
        $this->info('🍎 Creating iOS Certificate Signing Request (CSR)...');

        // Ensure credentials directory exists
        $credentialsDir = base_path('credentials');
        if (! is_dir($credentialsDir)) {
            mkdir($credentialsDir, 0755, true);
            $this->info('📁 Created credentials directory');
            $this->addCredentialsToGitignore();
        }

        // Check if openssl is available and get the command
        $opensslCommand = $this->getOpensslCommand();
        if (! $opensslCommand) {
            $this->error('❌ OpenSSL is not available. Please install OpenSSL.');

            return;
        }

        // Collect certificate information
        $this->info('📋 Certificate signing request information:');

        $keyName = text(
            label: 'Private key filename',
            default: 'ios-private-key.key',
            validate: fn (string $value) => match (true) {
                strlen($value) < 1 => 'Filename is required.',
                ! str_ends_with($value, '.key') => 'Filename must end with .key',
                default => null
            }
        );

        $csrName = text(
            label: 'CSR filename',
            default: 'ios-certificate-request.csr',
            validate: fn (string $value) => match (true) {
                strlen($value) < 1 => 'Filename is required.',
                ! str_ends_with($value, '.csr') => 'Filename must end with .csr',
                default => null
            }
        );

        $keyPath = $credentialsDir.DIRECTORY_SEPARATOR.$keyName;
        $csrPath = $credentialsDir.DIRECTORY_SEPARATOR.$csrName;

        if (file_exists($keyPath) || file_exists($csrPath)) {
            if (! confirm('Key or CSR files already exist. Overwrite?', false)) {
                $this->warn('⚠️ Skipping iOS credential creation.');

                return;
            }
        }

        $commonName = text(
            label: 'Your full name (CN) - optional',
            default: ''
        );

        $email = text(
            label: 'Email address - optional',
            default: '',
            validate: function (string $value) {
                if (strlen($value) === 0) {
                    return null;
                }
                if (! filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return 'Invalid email format if provided.';
                }

                return null;
            }
        );

        $organization = text(
            label: 'Organization (O) - optional',
            default: ''
        );

        $organizationalUnit = text(
            label: 'Organizational unit (OU) - optional',
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

        $keySize = '2048'; // Apple requires 2048-bit RSA keys

        // Generate private key first
        $this->info('🔐 Generating RSA private key...');

        $keyCommand = [
            $opensslCommand,
            'genrsa',
            '-out', $keyPath,
            $keySize,
        ];

        $keyResult = Process::run($keyCommand);

        if ($keyResult->failed()) {
            $this->error('❌ Failed to generate private key: '.$keyResult->errorOutput());

            return;
        }

        // Set proper permissions on private key
        chmod($keyPath, 0600);

        // Build subject string for CSR
        $subject = "/CN={$commonName}/emailAddress={$email}/O={$organization}/OU={$organizationalUnit}/L={$city}/ST={$state}/C=".strtoupper($country);

        // Generate CSR
        $this->info('📝 Generating Certificate Signing Request...');

        $csrCommand = [
            $opensslCommand,
            'req',
            '-new',
            '-key', $keyPath,
            '-out', $csrPath,
            '-subj', $subject,
        ];

        $csrResult = Process::run($csrCommand);

        if ($csrResult->failed()) {
            $this->error('❌ Failed to generate CSR: '.$csrResult->errorOutput());
            // Clean up the private key if CSR generation failed
            @unlink($keyPath);

            return;
        }

        $this->info('✅ iOS credentials created successfully!');
        $this->info("🔐 Private key: {$keyPath}");
        $this->info("📝 CSR file: {$csrPath}");

        //        $this->newLine();
        //        $this->info('📋 Next steps:');
        //        $this->line('1. Upload the CSR file to Apple Developer Portal');
        //        $this->line('2. Download the signed certificate (.cer file)');
        //        $this->line('3. Convert to .p12 format for app signing');

        $this->newLine();
        $this->warn('⚠️ Important: Keep your private key secure!');
        $this->line("- Private key: {$keyName}");
        $this->line("- CSR file: {$csrName}");

        // Display CSR content for easy copying
        //        if (confirm('Show CSR content for copying?', true)) {
        //            $this->newLine();
        //            $this->line('📋 CSR content (copy this to Apple Developer Portal):');
        //            $this->line('');
        //            $csrContent = file_get_contents($csrPath);
        //            $this->line($csrContent);
        //        }

        $this->newLine();
        $this->error('⚠️ IMPORTANT! Read and follow these steps!!');
        $this->info('🍎 Apple Developer Portal Setup');
        $this->line('To complete your iOS certificate setup:');
        $this->line('1. Go to https://developer.apple.com/account/resources/certificates');
        $this->line('2. Click the "+" button to create a new certificate');
        $this->line('3. Select "Apple Distribution"');
        $this->line('4. Upload the CSR file: '.$csrName);
        $this->line('5. Download the certificate (.cer file)');
        $this->line('6. Save it to the credentials directory with a .cer extension');

        if (confirm('Continue once you have downloaded the .cer file to the credentials directory?', false)) {
            $this->handleCertificateConversion($credentialsDir, $keyPath);
        }

        // Provide guidance about other credentials
        $this->newLine();
        $this->info('📋 Next Steps for Complete iOS Packaging Setup:');
        $this->line('1. Download your provisioning profile from Apple Developer Portal');
        $this->line('2. Save it as credentials/profile.mobileprovision');
        $this->line('3. Add to .env: IOS_DISTRIBUTION_PROVISIONING_PROFILE_PATH=credentials/profile.mobileprovision');
        $this->newLine();
        $this->line('For App Store Connect uploads:');
        $this->line('4. Download your API key (.p8) from App Store Connect');
        $this->line('5. Save it as credentials/AuthKey_[KEY_ID].p8');
        $this->line('6. Add to .env: APP_STORE_API_KEY_PATH=credentials/AuthKey_[KEY_ID].p8');
        $this->newLine();
        $this->info('💡 All credentials now use file paths for easier management!');
    }

    private function getOpensslCommand(): ?string
    {
        // Try different potential OpenSSL commands based on platform
        $commands = ['openssl'];

        // On Windows, openssl might be in different locations or have .exe extension
        if (PHP_OS_FAMILY === 'Windows') {
            $commands[] = 'openssl.exe';
        }

        foreach ($commands as $command) {
            $result = Process::run([$command, 'version']);
            if ($result->exitCode() === 0) {
                return $command;
            }
        }

        return null;
    }

    private function handleCertificateConversion(string $credentialsDir, string $keyPath): void
    {
        // Find .cer files in credentials directory
        $cerFiles = glob($credentialsDir.DIRECTORY_SEPARATOR.'*.cer');

        if (empty($cerFiles)) {
            $this->warn('⚠️ No .cer files found in the credentials directory.');
            $this->line('Please download your certificate from Apple Developer Portal and try again.');

            return;
        }

        // Let user select which certificate to use
        $cerFile = count($cerFiles) === 1 ?
            $cerFiles[0] :
            $this->selectCertificateFile($cerFiles);

        if (! $cerFile) {
            return;
        }

        $cerFileName = basename($cerFile);
        $this->info("📱 Using certificate: {$cerFileName}");

        // Detect certificate format
        $certFormat = $this->detectCertificateFormat($cerFile);
        $this->info("📋 Detected certificate format: {$certFormat}");

        // Ask for password for the P12 file
        $p12Password = password(
            label: 'Enter a password for the P12 certificate file'
        );

        if (empty($p12Password)) {
            $this->warn('⚠️ Password is required for P12 certificate.');

            return;
        }

        // Generate P12 file
        $p12FileName = str_replace('.cer', '.p12', $cerFileName);
        $p12Path = $credentialsDir.DIRECTORY_SEPARATOR.$p12FileName;

        $this->info('🔄 Converting certificate to P12 format...');

        $opensslCommand = $this->getOpensslCommand();
        if (! $opensslCommand) {
            $this->error('❌ OpenSSL is required for certificate conversion.');

            return;
        }

        // Convert DER to PEM if needed, since pkcs12 command expects PEM input
        $pemCertFile = $cerFile;
        if ($certFormat === 'DER') {
            $pemCertFile = $credentialsDir.DIRECTORY_SEPARATOR.'temp_cert.pem';
            $this->info('📋 Converting DER certificate to PEM format...');

            $convertCommand = [
                $opensslCommand,
                'x509',
                '-inform', 'DER',
                '-in', $cerFile,
                '-outform', 'PEM',
                '-out', $pemCertFile,
            ];

            $convertResult = Process::run($convertCommand);
            if ($convertResult->failed()) {
                $this->error('❌ Failed to convert DER to PEM: '.$convertResult->errorOutput());

                return;
            }
        }

        $p12Command = [
            $opensslCommand,
            'pkcs12',
            '-export',
            '-out', $p12Path,
            '-inkey', $keyPath,
            '-in', $pemCertFile,         // Use PEM format certificate
            '-passout', "pass:{$p12Password}",
            '-keypbe', 'PBE-SHA1-3DES',  // Use SHA1-3DES for private key encryption
            '-certpbe', 'PBE-SHA1-3DES', // Use SHA1-3DES for certificate encryption
            '-macalg', 'sha1',            // Use SHA1 for MAC (macOS compatible)
        ];

        $result = Process::run($p12Command);

        // Clean up temporary PEM file if we created one
        if ($certFormat === 'DER' && file_exists($pemCertFile)) {
            unlink($pemCertFile);
        }

        if ($result->failed()) {
            $this->error('❌ Failed to convert certificate to P12: '.$result->errorOutput());

            return;
        }

        $this->info('✅ P12 certificate created successfully!');
        $this->info("📁 Location: {$p12Path}");

        // Update environment variables
        $this->updateIosEnvVars($p12FileName, $p12Password);

        $this->newLine();
        $this->warn('⚠️ Important: Your P12 certificate and password are now configured!');
        $this->line("- P12 Certificate: {$p12FileName}");
        $this->line('- Password: [HIDDEN]');
    }

    private function selectCertificateFile(array $cerFiles): ?string
    {
        $options = [];
        foreach ($cerFiles as $file) {
            $fileName = basename($file);
            $options[$file] = $fileName;
        }

        return select(
            label: 'Select the certificate file to convert:',
            options: $options
        );
    }

    private function detectCertificateFormat(string $cerFile): string
    {
        // Try to read as PEM first (check for PEM headers)
        $content = file_get_contents($cerFile);
        if (strpos($content, '-----BEGIN CERTIFICATE-----') !== false) {
            return 'PEM';
        }

        // If no PEM headers, assume DER (Apple's default format)
        return 'DER';
    }

    private function updateIosEnvVars(string $p12File, string $p12Password): void
    {
        $envPath = base_path('.env');
        $passwordVar = 'IOS_DISTRIBUTION_CERTIFICATE_PASSWORD';
        $pathVar = 'IOS_DISTRIBUTION_CERTIFICATE_PATH';
        // Legacy variable for backward compatibility
        $legacyFileVar = 'IOS_DISTRIBUTION_CERTIFICATE_FILE';

        $envContent = '';
        $existingVars = [];

        // Read existing .env file if it exists
        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);

            // Check which variables already exist
            foreach ([$passwordVar, $pathVar, $legacyFileVar] as $var) {
                if (preg_match("/^{$var}=/m", $envContent)) {
                    $existingVars[] = $var;
                }
            }
        }

        // Add missing environment variables
        $addedVars = [];
        $p12Path = "credentials/{$p12File}";

        // Use the new path-based variables
        $vars = [
            $pathVar => $p12Path,
            $passwordVar => $p12Password,
        ];

        foreach ($vars as $key => $value) {
            if (! in_array($key, $existingVars)) {
                if (! str_ends_with($envContent, PHP_EOL) && ! empty($envContent)) {
                    $envContent .= PHP_EOL;
                }
                if (empty($addedVars)) {
                    $envContent .= PHP_EOL.'# iOS Certificate Configuration (File Path)'.PHP_EOL;
                }
                $envContent .= "{$key}={$value}".PHP_EOL;
                $addedVars[] = $key;
            }
        }

        // Write updated .env file
        if (! empty($addedVars)) {
            file_put_contents($envPath, $envContent);
            $this->info('📝 Added iOS certificate variables to .env:');
            foreach ($addedVars as $var) {
                $this->line("  - {$var}");
            }
            $this->newLine();
            $this->info('🎯 Certificate configured using file path approach:');
            $this->line("  • Path: {$p12Path}");
            $this->line('  • Password: [HIDDEN]');
        }

        // Inform about existing variables
        if (! empty($existingVars)) {
            $this->info('ℹ️  The following variables already exist in .env (not updated):');
            foreach ($existingVars as $var) {
                $this->line("  - {$var}");
            }
        }
    }
}
