<?php
//ini_set('display_errors', 1);
//ini_set('display_startup_errors', 1);
//error_reporting(E_ALL);

/**
* Transfiler: Downloader v1.0.0
* Copyight 2025 VR51, Lee Hodson, Gemini Flash 2
*
* 1. Place this script on Server Two (or in a subdirectory of Server One).
* 2. Configure the settings that start at line 20.
* 3. Open a browser at [domain]/download_files.php (or if in a subdirctory go to [domain]/[subdirectory]/download_files.php).
* 4. Fill in the form.
* 5. Paste in the name of the index file (not the link) created by the Indexer script.
* 6. Type in the domain name (or domain name/subdirectory) where the index file is located.
* 7. Click the Download Files button.
* 8. Delete this script and its log files from your web server after use. If you leave it in place someone else might use it to force your server to continuously download files which would take up a lot of resources.
*/

// Configuration Variables
// Ref: https://github.com/dyne/file-extension-list/blob/master/data/categories/3D.csv
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'csv']; // Example allowed extensions
$enable_extension_check = false;  // Set to false to disable extension check and just download whatever is in the index log on server one.
$max_file_size = 41943040; // 40MB in bytes
// Ref: https://mimetype.io/all-types
$allowed_mime_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv']; // Example mime types

// New:  Excluded file extensions
$excluded_extensions = ['php', 'exe', 'sh'];

// Log files
$downloaded_log_file = "downloaded_files.log";
$failed_log_file = "failed_files.log";
$new_files_log_file = "new_files.log"; // New log file for newly discovered files
$ignored_files_log_file = "ignored_files.log"; // New log file for skipped extensions

// Function to sanitize filename based on allowed extensions
function sanitize_filename($filename, $allowed_extensions) {
    // Get the file extension
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Check if the extension is in the allowed list
    if (!in_array($extension, $allowed_extensions)) {
        return false; // Reject the file if the extension is not allowed
    }

    // Sanitize the filename (remove special characters, spaces, etc.)
    $sanitized_filename = preg_replace("/[^a-zA-Z0-9._-]/", "", $filename);

    return $sanitized_filename; // Return the sanitized filename
}

$csv_file = isset($_POST['csv_file']) ? $_POST['csv_file'] : ''; // CSV file name
$remote_url = isset($_POST['remote_url']) ? $_POST['remote_url'] : ''; // URL to the directory containing the CSV file
$continue_download = isset($_POST['continue_download']) ? $_POST['continue_download'] === 'true' : false;

// Function to safely put content to file, including error handling
function safe_file_put_contents($filename, $content, $flags = 0) {
    try {
        $result = file_put_contents($filename, $content, $flags);
        if ($result === false) {
            $error = error_get_last();
            error_log("Failed to write to file " . $filename . ": " . $error['message']);
            return false;
        }
        return $result;
    } catch (Exception $e) {
        error_log("Exception occurred while writing to file " . $filename . ": " . $e->getMessage());
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // **SECURITY:** Set content type to JSON early to prevent HTML injection
    header('Content-Type: application/json; charset=utf-8');

    // Initialize counters
    $files_copied_count = 0;
    $directories_created_count = 0;
    $files_failed_count = 0;
    $directories_failed_count = 0;

    // Check if a downloaded log exists and we are not starting a new download
    if (file_exists($downloaded_log_file) && !$continue_download) {
        $downloaded_files_log_content = @file_get_contents($downloaded_log_file);  // Use @ to suppress warnings
        if ($downloaded_files_log_content === false) {
            error_log("Failed to read downloaded log file: " . $downloaded_log_file);
            echo json_encode(["status" => "error", "message" => "Error reading downloaded log file.  Starting new download.",
                              "files_copied_count" => $files_copied_count,
                              "directories_created_count" => $directories_created_count,
                              "files_failed_count" => $files_failed_count,
                              "directories_failed_count" => $directories_failed_count
                             ]);
            // Continue with new download
        } else {
            $downloaded_files_from_log = array_map('str_getcsv', explode("\n", $downloaded_files_log_content));
            array_shift($downloaded_files_from_log); // Remove header

            // Download the CSV file from the server to compare
            $csv_url = rtrim($remote_url, '/') . '/' . $csv_file;
            $ch = curl_init($csv_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // For HTTPS (careful with this in production)
            curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Set a timeout
            $csv_content = curl_exec($ch);
            if (curl_errno($ch)) {
                error_log("Failed to download CSV file for comparison: " . curl_error($ch));
                echo json_encode(["status" => "error", "message" => "Failed to download CSV file for comparison.  Starting new download.",
                                 "files_copied_count" => $files_copied_count,
                                 "directories_created_count" => $directories_created_count,
                                 "files_failed_count" => $files_failed_count,
                                 "directories_failed_count" => $directories_failed_count
                                ]);
                // Continue with new download
            } else {
                curl_close($ch);

                $new_files_found = false;
                $lines = explode("\n", $csv_content);
                array_shift($lines);  // Remove header

                //Initialize the New Files Log file
                if (!safe_file_put_contents($new_files_log_file, "Name,Relative Path,Type,Size\n")) {
                    error_log("Failed to create new files log file header: " . $new_files_log_file);
                    echo json_encode(["status" => "error", "message" => "Failed to create new files log file. Download aborted.",
                                      "files_copied_count" => $files_copied_count,
                                      "directories_created_count" => $directories_created_count,
                                      "files_failed_count" => $files_failed_count,
                                      "directories_failed_count" => $directories_failed_count
                                     ]);
                    exit;
                }

                // Initialize the Ignored Files Log file
                if (!safe_file_put_contents($ignored_files_log_file, "Name,Relative Path,Type,Size\n")) {
                    error_log("Failed to create ignored files log file header: " . $ignored_files_log_file);
                    echo json_encode(["status" => "error", "message" => "Failed to create ignored files log file. Download aborted.",
                                      "files_copied_count" => $files_copied_count,
                                      "directories_created_count" => $directories_created_count,
                                      "files_failed_count" => $files_failed_count,
                                      "directories_failed_count" => $directories_failed_count
                                     ]);
                    exit;
                }

                foreach ($lines as $line) {
                    $data = str_getcsv($line);
                    if (count($data) === 4) {
                        $name = $data[0];
                        $relative_path = $data[1];
                        $type = $data[2];
                        $size = $data[3];

                        $found_in_log = false;
                        foreach ($downloaded_files_from_log as $downloaded_file) {
                            if (count($downloaded_file) >= 2 && $downloaded_file[0] === $name && $downloaded_file[1] === $relative_path) {
                                $found_in_log = true;
                                break;
                            }
                        }

                        if (!$found_in_log && !empty($name)) {
                            $new_files_found = true;

                            // Write the new file to the log file
                            if (!safe_file_put_contents($new_files_log_file, '"' . str_replace('"', '""', $name) . '","' . $relative_path . '","' . $type . '","' . $size . '"' . "\n", FILE_APPEND)) {
                                error_log("Failed to write to new files log file: " . $name);
                            }
                        }
                    }
                }

                if ($new_files_found) {
                    // Offer to continue the previous download
                    echo json_encode(["status" => "continue", "message" => "A previous download log was found.  New files have been detected in the CSV file.  Do you want to continue the previous download?", "csv_file" => $csv_file, "remote_url" => $remote_url,
                                      "files_copied_count" => $files_copied_count,
                                      "directories_created_count" => $directories_created_count,
                                      "files_failed_count" => $files_failed_count,
                                      "directories_failed_count" => $directories_failed_count,
                                      "num_files_to_download" => $num_files_to_download
                                     ]);
                    exit;
                } else {
                    //If no new files are found then provide a message stating this fact.
                    echo json_encode(["status" => "success", "message" => "No new files found. The script has already downloaded all files in the index.",
                                      "files_copied_count" => $files_copied_count,
                                      "directories_created_count" => $directories_created_count,
                                      "files_failed_count" => $files_failed_count,
                                      "directories_failed_count" => $directories_failed_count,
                                      "num_files_to_download" => $num_files_to_download
                                     ]);
                    exit;
                }
            }
        }
    }

    // Validate remote URL (basic check - improve this)
    if (filter_var($remote_url, FILTER_VALIDATE_URL) === FALSE) {
        echo json_encode(["status" => "error", "message" => "Invalid remote URL.",
                          "files_copied_count" => $files_copied_count,
                          "directories_created_count" => $directories_created_count,
                          "files_failed_count" => $files_failed_count,
                          "directories_failed_count" => $directories_failed_count,
                          "num_files_to_download" => $num_files_to_download
                         ]);
        exit;
    }

    // Download the CSV file securely (use cURL with proper options)
    $csv_url = rtrim($remote_url, '/') . '/' . $csv_file; // Create the full CSV URL

    $ch = curl_init($csv_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);  // For HTTPS (careful with this in production)
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Set a timeout
    $csv_content = curl_exec($ch);

    if (curl_errno($ch)) {
        echo json_encode(["status" => "error", "message" => "Failed to download CSV file: " . curl_error($ch),
                          "files_copied_count" => $files_copied_count,
                          "directories_created_count" => $directories_created_count,
                          "files_failed_count" => $files_failed_count,
                          "directories_failed_count" => $directories_failed_count,
                          "num_files_to_download" => $num_files_to_download
                         ]);
        curl_close($ch);
        exit;
    }
    curl_close($ch);

    if ($csv_content === false) {
        echo json_encode(["status" => "error", "message" => "Failed to download CSV file.",
                          "files_copied_count" => $files_copied_count,
                          "directories_created_count" => $directories_created_count,
                          "files_failed_count" => $files_failed_count,
                          "directories_failed_count" => $directories_failed_count,
                          "num_files_to_download" => $num_files_to_download
                         ]);
        exit;
    }

    $lines = explode("\n", $csv_content);
    $total_size = 0;
    $file_list = [];
    $num_files_to_download = 0;  // Counter for files to download
    foreach ($lines as $line) {
        $data = str_getcsv($line); // Parse CSV

        // **SOLUTION:** Check if data is not empty before proceeding
        if (!empty($data) && count($data) === 4) {
            list($name, $relative_path, $type, $size) = $data;

            // Check for excluded extension before adding to download list
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if($type === "file" && !in_array($extension, $excluded_extensions)) {
                $num_files_to_download++;
            }

            //Add the directory to the list
            if($type === "directory"){
                $file_list[] = ["name" => $name, "relative_path" => $relative_path, "type" => $type, "size" => $size];
            }

            if ($type === "file") {
                $total_size += (int)$size;
                $file_list[] = ["name" => $name, "relative_path" => $relative_path, "type" => $type, "size" => $size]; // Store type as well
            }
        }
    }

    $free_space = disk_free_space("."); // Get free disk space in bytes

    if ($total_size > $free_space) {
        echo json_encode(["status" => "error", "message" => "Insufficient disk space. Required: " . $total_size . " bytes, Available: " . $free_space . " bytes.",
                          "files_copied_count" => $files_copied_count,
                          "directories_created_count" => $directories_created_count,
                          "files_failed_count" => $files_failed_count,
                          "directories_failed_count" => $directories_failed_count,
                          "num_files_to_download" => $num_files_to_download
                         ]);
        exit;
    }

    //Log file headers (if not continuing)
    if (!$continue_download || !file_exists($downloaded_log_file)){
        if (!safe_file_put_contents($downloaded_log_file, "Name,Relative Path,Type,Size\n")) {
            error_log("Failed to create downloaded log file header: " . $downloaded_log_file);
            echo json_encode(["status" => "error", "message" => "Failed to create downloaded log file. Download aborted.",
                              "files_copied_count" => $files_copied_count,
                              "directories_created_count" => $directories_created_count,
                              "files_failed_count" => $files_failed_count,
                              "directories_failed_count" => $directories_failed_count,
                              "num_files_to_download" => $num_files_to_download
                             ]);
            exit;
        }
    }

    if (!$continue_download || !file_exists($failed_log_file)){
        if (!safe_file_put_contents($failed_log_file, "Name,Relative Path,Type,Size\n")) {
            error_log("Failed to create failed log file header: " . $failed_log_file);
            echo json_encode(["status" => "error", "message" => "Failed to create failed log file. Download aborted.",
                              "files_copied_count" => $files_copied_count,
                              "directories_created_count" => $directories_created_count,
                              "files_failed_count" => $files_failed_count,
                              "directories_failed_count" => $directories_failed_count,
                              "num_files_to_download" => $num_files_to_download
                             ]);
            exit;
        }
    }

    if (!$continue_download || !file_exists($new_files_log_file)){
      if (!safe_file_put_contents($new_files_log_file, "Name,Relative Path,Type,Size\n")) {
          error_log("Failed to create new files log file header: " . $new_files_log_file);
          echo json_encode(["status" => "error", "message" => "Failed to create new files log file. Download aborted.",
                            "files_copied_count" => $files_copied_count,
                            "directories_created_count" => $directories_created_count,
                            "files_failed_count" => $files_failed_count,
                            "directories_failed_count" => $directories_failed_count,
                            "num_files_to_download" => $num_files_to_download
                           ]);
          exit;
      }
    }

    if (!$continue_download || !file_exists($ignored_files_log_file)){
      if (!safe_file_put_contents($ignored_files_log_file, "Name,Relative Path,Type,Size\n")) {
          error_log("Failed to create ignored files log file header: " . $ignored_files_log_file);
          echo json_encode(["status" => "error", "message" => "Failed to create ignored files log file. Download aborted.",
                            "files_copied_count" => $files_copied_count,
                            "directories_created_count" => $directories_created_count,
                            "files_failed_count" => $files_failed_count,
                            "directories_failed_count" => $directories_failed_count,
                            "num_files_to_download" => $num_files_to_download
                           ]);
          exit;
      }
    }

    // Begin File Download and Directory Recreation Process
    $downloaded_files = [];
    $failed_files = [];
    $downloaded_files_from_log = [];

    if (file_exists($downloaded_log_file)) {
        $downloaded_files_log_content = @file_get_contents($downloaded_log_file);
        if ($downloaded_files_log_content === false) {
            error_log("Failed to read downloaded log file for file skipping.");
            echo json_encode(["status" => "error", "message" => "Failed to read downloaded log file. Download aborted.",
                              "files_copied_count" => $files_copied_count,
                              "directories_created_count" => $directories_created_count,
                              "files_failed_count" => $files_failed_count,
                              "directories_failed_count" => $directories_failed_count,
                              "num_files_to_download" => $num_files_to_download
                             ]);
            exit;
        }
        $downloaded_files_from_log = array_map('str_getcsv', explode("\n", $downloaded_files_log_content));
        array_shift($downloaded_files_from_log); // Remove header
    }

    foreach ($file_list as $file) {
        $name = $file["name"];
        $relative_path = $file["relative_path"];
        $type = $file["type"];
        $size = $file["size"];
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // Check if this file was already downloaded
        $already_downloaded = false;
        foreach ($downloaded_files_from_log as $downloaded_file) {
            if (count($downloaded_file) >= 2 && $downloaded_file[0] === $name && $downloaded_file[1] === $relative_path) {
                $already_downloaded = true;
                break;
            }
        }

        if ($already_downloaded && $continue_download) {
            echo "<script>console.log('Skipping already downloaded: " . htmlspecialchars($name) . "');$('#result').append('<p>Skipping already downloaded: " . htmlspecialchars($name) . "</p>');</script>";
            flush();
            continue;
        }

        // Check for excluded extension
        if (in_array($extension, $excluded_extensions) && $type == "file") {
            echo "<script>console.log('Skipping excluded extension: " . htmlspecialchars($name) . "');$('#result').append('<p>Skipping excluded extension: " . htmlspecialchars($name) . "</p>');</script>";
            // Log the ignored file
            if (!safe_file_put_contents($ignored_files_log_file, '"' . str_replace('"', '""', $name) . '","' . $relative_path . '","' . $type . '","' . $size . '"' . "\n", FILE_APPEND)) {
                error_log("Failed to write to ignored files log file: " . $name);
            }
            continue;
        }

        //Output what file is being downloaded
        echo "<script>console.log('Processing: " . htmlspecialchars($name) . "');$('#result').append('<p>Processing: " . htmlspecialchars($name) . "</p>');</script>";
        flush(); //Send output immediately

        if ($relative_path === ".") {
            $relative_path = ""; // Empty string if it's the base directory
        }

        if ($type === "directory") {
            // Create directory
            if (!is_dir($relative_path)) {
                if (!mkdir($relative_path, 0777, true)) {
                  $directories_failed_count++;
                  echo "<script>$('#directoriesFailedCount').text(" . $directories_failed_count . ");$('#result').append('<p>Failed to create directory: " . htmlspecialchars($relative_path) . "</p>');</script>";
                    error_log("Failed to create directory: " . $relative_path);
                    continue;
                }
                $directories_created_count++;
                echo "<script>$('#directoriesCreatedCount').text(" . $directories_created_count . ");</script>";
            }

            // Log the directory creation
            if (!safe_file_put_contents($downloaded_log_file, '"' . str_replace('"', '""', $name) . '","' . $relative_path . '","' . $type . '","' . $size . '"' . "\n", FILE_APPEND)) {
                error_log("Failed to write directory to downloaded log file: " . $name);
            }
            continue; // Skip to the next file
        }

        // Download the file
        $file_url = rtrim($remote_url, '/') . '/' . $relative_path; // Create full URL

        // Sanitize Filename and Check File Extension
        $sanitized_filename = $name; // Default: don't sanitize the filename for downloading
        if ($enable_extension_check) {
            $sanitized_filename = sanitize_filename($name, $allowed_extensions);
            if ($sanitized_filename === false) {
                error_log("File extension not allowed: " . $name); //Log the error
                $files_failed_count++;
                echo "<script>$('#filesFailedCount').text(" . $files_failed_count . ");$('#result').append('<p>File extension not allowed: " . htmlspecialchars($name) . "</p>');</script>";

                $failed_files[] = $file; //Add the file to the failed log
                if (!safe_file_put_contents($failed_log_file, '"' . str_replace('"', '""', $name) . '","' . $relative_path . '","' . $type . '","' . $size . '"' . "\n", FILE_APPEND)) {
                    error_log("Failed to write to failed log file: " . $name);
                }
                continue; //Skip the download
            }
        }

        $ch = curl_init($file_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For HTTPS (careful in production)
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $file_content = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log("Failed to download file: " . $name . " - " . curl_error($ch)); //Log the error
            $files_failed_count++;
            echo "<script>$('#filesFailedCount').text(" . $files_failed_count . ");$('#result').append('<p>Failed to download file: " . htmlspecialchars($name) . "</p>');</script>";

            $failed_files[] = $file; //Add the file to the failed log
            if (!safe_file_put_contents($failed_log_file, '"' . str_replace('"', '""', $name) . '","' . $relative_path . '","' . $type . '","' . $size . '"' . "\n", FILE_APPEND)) {
                error_log("Failed to write to failed log file: " . $name);
            }

            curl_close($ch);
            continue; // Skip to the next file
        }
        curl_close($ch);

        // Write to the new File Path
        $target_file = $relative_path;
        $result = safe_file_put_contents($target_file, $file_content);

        if ($result === false) {
            error_log("Failed to write file: " . $name); //Log the error
            $files_failed_count++;
            echo "<script>$('#filesFailedCount').text(" . $files_failed_count . ");$('#result').append('<p>Failed to write file: " . htmlspecialchars($name) . "</p>');</script>";

            $failed_files[] = $file; //Add the file to the failed log
            if (!safe_file_put_contents($failed_log_file, '"' . str_replace('"', '""', $name) . '","' . $relative_path . '","' . $type . '","' . $size . '"' . "\n", FILE_APPEND)) {
                error_log("Failed to write to failed log file: " . $name);
            }

            continue; //Skip to the next file
        }

        // Verify File Size
        $downloaded_file_size = filesize($target_file);
        if ($downloaded_file_size != $size) {
            error_log("Downloaded file size mismatch: " . $name . " (expected: " . $size . ", actual: " . $downloaded_file_size . ")");
            $files_failed_count++;
            echo "<script>$('#filesFailedCount').text(" . $files_failed_count . ");$('#result').append('<p>Downloaded file size mismatch: " . htmlspecialchars($name) . "</p>');</script>";
            $failed_files[] = $file;
            if (!safe_file_put_contents($failed_log_file, '"' . str_replace('"', '""', $name) . '","' . $relative_path . '","' . $type . '","' . $size . '"' . "\n", FILE_APPEND)) {
                error_log("Failed to write to failed log file: " . $name);
            }
            //Attempt to delete the file to prevent partial downloads
            unlink($target_file);
            continue; //Skip to the next file
        }

        $downloaded_files[] = $name;
        $files_copied_count++;
        echo "<script>$('#filesCopiedCount').text(" . $files_copied_count . ");</script>";
        if (!safe_file_put_contents($downloaded_log_file, '"' . str_replace('"', '""', $name) . '","' . $relative_path . '","' . $type . '","' . $size . '"' . "\n", FILE_APPEND)) {
            error_log("Failed to write to downloaded log file: " . $name);
        }

    }

    $message = "Files downloaded: " . implode(", ", $downloaded_files);
    if (!empty($failed_files)) {
        $message .= "<br>Failed to download: " . count($failed_files) . " files. Check the error log for details.";
    }

    echo "<script>$('#downloadButton').prop('disabled', false);</script>";

    echo json_encode(["status" => "success", "message" => $message,
                      "files_copied_count" => $files_copied_count,
                      "directories_created_count" => $directories_created_count,
                      "files_failed_count" => $files_failed_count,
                      "directories_failed_count" => $directories_failed_count,
                      "num_files_to_download" => $num_files_to_download
                     ]);

    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>File Downloader</title>
    <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script>
        $(document).ready(function() {
            let numFilesToDownload = 0; // Variable to store the number of files to download

            $("#downloaderForm").submit(function(e) {
                e.preventDefault();
                //Disable the download button
                $('#downloadButton').prop('disabled', true);
                //Reset the counts
                $("#filesCopiedCount").text(0);
                $("#directoriesCreatedCount").text(0);
                $("#filesFailedCount").text(0);
                $("#directoriesFailedCount").text(0);
                // Clear the result div
                $("#result").empty();

                var formData = {
                    csv_file: $("#csv_file").val(),
                    remote_url: $("#remote_url").val(),
                    continue_download: $("#continue_download").val()
                };
                $.ajax({
                    type: "POST",
                    url: "download_files.php",
                    data: formData,
                    dataType: "json",
                    success: function(data) {
                        if(data.status === "continue") {
                            // Offer to continue previous download
                            if (confirm(data.message)) {
                                // Continue previous download
                                $("#continue_download").val(true); //Hidden Field
                                $("#downloaderForm").submit();
                            } else {
                                // Start a new download
                                $("#continue_download").val(false);
                                $("#result").html("<p>Starting a new download.</p>");
                                //Enable the download button
                                $('#downloadButton').prop('disabled', false);
                                // Update button text
                                $("#downloadButton").text(`Download ${numFilesToDownload} files`);
                            }

                        } else {
                            $("#result").html("<p>" + data.message + "</p>");
                            $("#filesCopiedCount").text(data.files_copied_count);
                            $("#directoriesCreatedCount").text(data.directories_created_count);
                            $("#filesFailedCount").text(data.files_failed_count);
                            $("#directoriesFailedCount").text(data.directories_failed_count);
                            //Enable the download button
                            $('#downloadButton').prop('disabled', false);

                            // Update button text
                            numFilesToDownload = data.num_files_to_download;
                            $("#downloadButton").text(`Download ${numFilesToDownload} files`);
                        }

                    },
                    error: function(xhr, status, error) {
                        $("#result").html("<p>AJAX Error: " + error + "</p>");
                        //Enable the download button
                        $('#downloadButton').prop('disabled', false);

                        // Update button text - use existing stored value if available
                        $("#downloadButton").text(`Download ${numFilesToDownload} Files`);
                    }
                });
            });

            // Initial button text - defaults to 0 if number of files is not known
            $("#downloadButton").text(`Download Files`);
        });
    </script>
</head>
<body>
    <h1>File Downloader</h1>
    <form id="downloaderForm">
        CSV File: <input type="text" id="csv_file" name="csv_file"><br><br>
        Remote URL: <input type="text" id="remote_url" name="remote_url"><br><br>
        <input type="hidden" id="continue_download" name="continue_download" value="false">
        <button type="submit" id="downloadButton">Download Files</button>
    </form>
    <div>
        <br><br>
        Files Copied: <span id="filesCopiedCount">0</span><br>
        Directories Created: <span id="directoriesCreatedCount">0</span><br>
        Files Failed: <span id="filesFailedCount">0</span><br>
        Directories Failed: <span id="directoriesFailedCount">0</span><br>
        <br><br>
    </div>
    <div id="result"></div>
</body>
</html>
