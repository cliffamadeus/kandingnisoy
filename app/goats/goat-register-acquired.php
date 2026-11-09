<?php
require_once '../../config/config.php';
require_once '../../config/functions.php';
require_once '../../includes/goat-logger.php';
require_once '../../includes/goat-functions.php';
require_once '../../includes/goat-upload-functions.php';
requireLogin();

$currentRole = $_SESSION['role'];
$currentUserId = $_SESSION['user_id'];

if (!canRegisterGoat($currentRole)) {
    die("Access denied. Only Farmers can register goats.");
}

// Farmers for the Admin "register on behalf of" dropdown
$farmers = [];
if ($currentRole === 'admin') {
    $farmers = $pdo->query("SELECT id, full_name, username FROM users WHERE role = 'user' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$message = '';
$success = false;
$duplicateBlocked = false;

// Preserve submitted values on validation failure
$goat_id_input = '';
$goat_name = '';
$tag_number = '';
$sex = '';
$breed = '';
$date_of_birth = '';
$color = '';
$identifying_characteristics = '';
$date_acquired = date('Y-m-d');
$previous_owner_note = '';
$owner_id_input = ($currentRole === 'admin') ? 0 : $currentUserId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $goat_id_input = trim($_POST['goat_id'] ?? '');
    $goat_name = trim($_POST['goat_name'] ?? '');
    $tag_number = trim($_POST['tag_number'] ?? '');
    $sex = normalizeGoatSex($_POST['sex'] ?? '');
    $breed = trim($_POST['breed'] ?? '');
    $date_of_birth = trim($_POST['date_of_birth'] ?? '');
    $color = trim($_POST['color'] ?? '');
    $identifying_characteristics = trim($_POST['identifying_characteristics'] ?? '');
    $date_acquired = trim($_POST['date_acquired'] ?? '');
    // Free-text note only — never used to look up or auto-link an account.
    // The previous owner may not even be registered in the system.
    $previous_owner_note = trim($_POST['previous_owner_note'] ?? '');

    // The owning Farmer's User ID always comes from the authenticated
    // session (or, for an Admin, a server-verified Farmer selected from
    // a real list) — never from raw client input.
    if ($currentRole === 'admin') {
        $owner_id_input = (int)($_POST['owner_id'] ?? 0);
        $ownerIsValidFarmer = false;
        foreach ($farmers as $f) {
            if ((int)$f['id'] === $owner_id_input) { $ownerIsValidFarmer = true; break; }
        }
        $ownerId = $ownerIsValidFarmer ? $owner_id_input : 0;
    } else {
        $ownerId = $currentUserId;
    }

    $errors = [];
    if ($goat_id_input === '' || !preg_match('/^[A-Za-z0-9._-]{1,50}$/', $goat_id_input)) {
        $errors[] = "Goat ID is required (letters, numbers, dot, dash, underscore only, max 50 characters).";
    }
    if ($goat_name === '') $errors[] = "Goat Name/Tag Number label is required.";
    if ($sex === null) $errors[] = "Sex must be Male or Female.";
    if ($breed === '') $errors[] = "Breed is required.";
    if ($date_of_birth !== '' && !DateTime::createFromFormat('Y-m-d', $date_of_birth)) {
        $errors[] = "Date of Birth must be a valid date.";
    }
    if ($date_acquired === '' || !DateTime::createFromFormat('Y-m-d', $date_acquired)) {
        $errors[] = "Date Acquired is required and must be a valid date.";
    }
    if ($ownerId <= 0) {
        $errors[] = ($currentRole === 'admin') ? "Please select a valid Farmer to register this goat under." : "Unable to determine owner.";
    }

    // Duplicate Goat ID check happens before we bother validating/storing
    // a photo, so we don't waste an upload on a request we're going to reject.
    $isDuplicate = false;
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM goats WHERE goat_id = ?");
        $stmt->execute([$goat_id_input]);
        if ($stmt->fetch()) {
            $isDuplicate = true;
        }
    }

    if ($isDuplicate) {
        $duplicateBlocked = true;
        $message = "This Goat ID is already registered and cannot be claimed. If this goat genuinely belongs to you, please contact your MVO/Administrator to use the system's existing ownership verification process.";
        $success = false;
        logGoatActivity($pdo, $currentUserId, $_SESSION['email'], 'goat_acquired_registration_rejected_duplicate', 'failed', $goat_id_input);
    } elseif (!empty($errors)) {
        $message = implode(' ', $errors);
        $success = false;
    } else {
        // Photo is required supporting evidence for a newly acquired goat.
        $photoResult = storeGoatPhoto($_FILES['goat_photo'] ?? [], $goat_id_input);
        if (!$photoResult['success']) {
            $message = $photoResult['error'];
            $success = false;
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO goats
                        (goat_id, user_id, goat_name, tag_number, sex, breed, date_of_birth, color,
                         identifying_characteristics, date_acquired, health_status, status,
                         verification_status, photo_path, date_registered)
                    VALUES
                        (:goat_id, :user_id, :goat_name, :tag_number, :sex, :breed, :dob, :color,
                         :characteristics, :date_acquired, 'unknown', 'active',
                         'pending', :photo_path, NOW())
                ");
                $stmt->execute([
                    ':goat_id' => $goat_id_input,
                    ':user_id' => $ownerId,
                    ':goat_name' => $goat_name,
                    ':tag_number' => $tag_number !== '' ? $tag_number : null,
                    ':sex' => $sex,
                    ':breed' => $breed,
                    ':dob' => $date_of_birth !== '' ? $date_of_birth : null,
                    ':color' => $color !== '' ? $color : null,
                    ':characteristics' => $identifying_characteristics !== '' ? $identifying_characteristics : null,
                    ':date_acquired' => $date_acquired,
                    ':photo_path' => $photoResult['relative_path'],
                ]);

                $message = "Your goat registration has been submitted and is now pending MVO/Manager verification. It will appear in your inventory as Verified once approved.";
                $success = true;

                logGoatActivity($pdo, $currentUserId, $_SESSION['email'], 'goat_pending_registration_submitted', 'success', $goat_id_input, [
                    'owner_id' => $ownerId,
                    'previous_owner_note' => $previous_owner_note !== '' ? $previous_owner_note : null,
                    'photo_path' => $photoResult['relative_path'],
                ]);

                // Clear form on success
                $goat_id_input = ''; $goat_name = ''; $tag_number = ''; $sex = ''; $breed = '';
                $date_of_birth = ''; $color = ''; $identifying_characteristics = '';
                $date_acquired = date('Y-m-d'); $previous_owner_note = '';

            } catch (PDOException $e) {
                if ($e->getCode() == 23000) {
                    $duplicateBlocked = true;
                    $message = "This Goat ID is already registered and cannot be claimed.";
                    logGoatActivity($pdo, $currentUserId, $_SESSION['email'], 'goat_acquired_registration_rejected_duplicate', 'failed', $goat_id_input);
                } else {
                    $message = "Error submitting registration. Please try again.";
                    logGoatActivity($pdo, $currentUserId, $_SESSION['email'], 'goat_pending_registration_submitted', 'failed', $goat_id_input);
                }
                $success = false;
            }
        }
    }
}

$title = 'Register Newly Acquired Goat';
renderHeader($title);
?>

<div class="nav">
    <a href="<?php echo BASE_URL; ?>/app/goats/dashboard.php">Back to Goat Inventory</a>
    <a href="<?php echo BASE_URL; ?>/app/goats/goat-create.php">Register Own Goat</a>
    <a href="<?php echo BASE_URL; ?>/app/auth/signout.php">Logout</a>
</div>

<div class="card">
    <h2>Register Newly Acquired Goat</h2>
    <p style="color: #666;">
        Use this form for a goat you have newly acquired from another farmer — including if that
        farmer is not registered in this system. A clear photo is required as supporting evidence.
        Your submission will be held as <strong>Pending</strong> until an MVO/Manager reviews and
        verifies it.
    </p>

    <?php if ($message): ?>
        <div class="<?php echo $success ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <?php if ($currentRole === 'admin'): ?>
        <div class="form-group">
            <label for="owner_id">Farmer (Owner):</label>
            <select id="owner_id" name="owner_id" required>
                <option value="">-- Select Farmer --</option>
                <?php foreach ($farmers as $f): ?>
                    <option value="<?php echo $f['id']; ?>" <?php echo $owner_id_input === (int)$f['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($f['full_name'] . ' (' . $f['username'] . ')'); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small style="color: #666;">Administrator registration on behalf of a Farmer. This is logged.</small>
        </div>
        <?php else: ?>
            <input type="hidden" name="owner_id" value="<?php echo $currentUserId; ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="goat_id">Goat ID (unique):</label>
            <input type="text" id="goat_id" name="goat_id" required maxlength="50"
                   value="<?php echo htmlspecialchars($goat_id_input); ?>" placeholder="e.g. G-045">
        </div>

        <div class="form-group">
            <label for="goat_name">Goat Name / Tag Number:</label>
            <input type="text" id="goat_name" name="goat_name" required
                   value="<?php echo htmlspecialchars($goat_name); ?>">
        </div>

        <div class="form-group">
            <label for="tag_number">Ear Tag Number (optional):</label>
            <input type="text" id="tag_number" name="tag_number"
                   value="<?php echo htmlspecialchars($tag_number); ?>">
        </div>

        <div class="form-group">
            <label for="sex">Sex:</label>
            <select id="sex" name="sex" required>
                <option value="">-- Select --</option>
                <option value="male" <?php echo $sex === 'male' ? 'selected' : ''; ?>>Male</option>
                <option value="female" <?php echo $sex === 'female' ? 'selected' : ''; ?>>Female</option>
            </select>
        </div>

        <div class="form-group">
            <label for="breed">Breed:</label>
            <input type="text" id="breed" name="breed" required
                   value="<?php echo htmlspecialchars($breed); ?>">
        </div>

        <div class="form-group">
            <label for="date_of_birth">Date of Birth (if known):</label>
            <input type="date" id="date_of_birth" name="date_of_birth"
                   value="<?php echo htmlspecialchars($date_of_birth); ?>">
        </div>

        <div class="form-group">
            <label for="color">Color:</label>
            <input type="text" id="color" name="color"
                   value="<?php echo htmlspecialchars($color); ?>">
        </div>

        <div class="form-group">
            <label for="identifying_characteristics">Other Identifying Characteristics:</label>
            <textarea id="identifying_characteristics" name="identifying_characteristics" rows="3"
                      style="width:100%; padding:12px; border:1px solid #ddd; border-radius:4px; font-family:'Roboto', Arial, sans-serif;"><?php echo htmlspecialchars($identifying_characteristics); ?></textarea>
        </div>

        <div class="form-group">
            <label for="date_acquired">Date Acquired:</label>
            <input type="date" id="date_acquired" name="date_acquired" required
                   value="<?php echo htmlspecialchars($date_acquired); ?>">
        </div>

        <div class="form-group">
            <label for="previous_owner_note">Previous Owner (optional note):</label>
            <input type="text" id="previous_owner_note" name="previous_owner_note"
                   value="<?php echo htmlspecialchars($previous_owner_note); ?>"
                   placeholder="e.g. Name/village of the farmer you acquired this goat from, if known">
            <small style="color: #666;">Informational only — not used to look up an account. The previous owner does not need to be registered in this system.</small>
        </div>

        <div class="form-group">
            <label for="goat_photo">Photo of the Goat (required):</label>
            <input type="file" id="goat_photo" name="goat_photo" accept="image/jpeg,image/png,image/webp" capture="environment" required>
            <small style="color: #666;">JPG, PNG, or WEBP, up to 5MB. This photo is supporting evidence reviewed by the MVO/Manager — it is not automatic proof of ownership.</small>
        </div>

        <button type="submit">
            <span class="material-icons" style="vertical-align: middle; font-size: 18px;">add_a_photo</span>
            Submit for Verification
        </button>
    </form>
</div>

<?php renderFooter(); ?>