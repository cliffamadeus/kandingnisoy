<?php
require_once 'config/config.php';
require_once 'config/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy - GOAT PRO</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 text-gray-800 font-sans antialiased min-h-screen flex flex-col">

    <!-- Navigation -->
    <nav class="bg-white shadow-sm border-b border-gray-200 py-4">
        <div class="max-w-4xl mx-auto px-4 flex justify-between items-center">
            <a href="<?php echo BASE_URL; ?>/index.php" class="flex items-center gap-2 text-xl font-bold text-green-700">
                <i class="fas fa-leaf"></i> GOAT PRO
            </a>
            <a href="<?php echo BASE_URL; ?>/login.php" class="text-sm font-semibold text-gray-600 hover:text-green-600 transition">
                <i class="fas fa-arrow-left"></i> Back to Login
            </a>
        </div>
    </nav>

    <!-- Content -->
    <main class="flex-grow max-w-4xl mx-auto px-4 py-12 w-full">
        <div class="bg-white p-8 md:p-12 rounded-2xl shadow-sm border border-gray-100">
            <h1 class="text-3xl font-extrabold text-gray-900 mb-6 border-b pb-4">Privacy Policy</h1>
            <p class="text-sm text-gray-500 mb-8">Last updated: <?php echo date('F j, Y'); ?></p>

            <div class="space-y-6 text-gray-700 leading-relaxed">
                <section>
                    <h2 class="text-xl font-bold text-gray-900 mb-2">1. Information We Collect</h2>
                    <p>When you register for the GOAT PRO - Precision Livestock Farming System, we collect personal information such as your full name, email address, contact number, and farm location (Barangay/Municipality). We also collect data regarding your goat inventory (Goat ID, breed, health status).</p>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-gray-900 mb-2">2. How We Use Your Information</h2>
                    <ul class="list-disc pl-5 space-y-2 mt-2">
                        <li>To provide accurate <strong>Weather Advisories</strong> via SMS based on your farm location.</li>
                        <li>To manage and track your goat inventory.</li>
                        <li>To allow the Municipal Agriculture Office (MAO) and Municipal Veterinary Office to generate reports for livestock planning.</li>
                    </ul>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-gray-900 mb-2">3. SMS Data & Consent</h2>
                    <p>By opting in during registration, you agree to receive automated text messages (SMS) containing weather warnings and farm tips. We do not send marketing messages. You may opt out of receiving SMS advisories at any time by contacting the system administrator.</p>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-gray-900 mb-2">4. Data Sharing and Protection</h2>
                    <p>Your data is stored securely in our database and is only accessible to authorized personnel (Admins and MAO Managers). We do not sell, trade, or share your personal information with third-party companies. Our system utilizes trusted third-party APIs (OpenWeather for weather data and TextBee for SMS delivery) solely for system functionality.</p>
                </section>

                <section>
                    <h2 class="text-xl font-bold text-gray-900 mb-2">5. Contact Us</h2>
                    <p>If you have any questions about this Privacy Policy, please contact the developers at Northern Bukidnon State College.</p>
                </section>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white border-t border-gray-200 py-6 text-center text-sm text-gray-500">
        <p>&copy; <?php echo date('Y'); ?> Kandingnisoy. All rights reserved.</p>
    </footer>
</body>
</html>