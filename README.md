# Transfiler
Web browser file transfer app. Index files on Server One, download files to Server Two. As simple and uncomplicated as can be.

# Instructions

* Script One: Transfiler: Indexer
* Script Two : Transfiler: Downloader
* Use script one -- the indexer -- to create an index of all files stored on a server or to create an index of all files with specified file extensions that live in the indexer script's directory and in sub directories thereof.
* Use script two -- the downloader -- to download all files indexed by script one, the indexer. The downloader script downloads files and stores them in a recreated directory structure that mirrors the directory structure of the source server.
* Script one goes on server one and script two goes on server two (or in a subdirectory of server one if copying select files from location A on server one to location B on server one).

# Use Cases

* To copy files from one server to another server. This is useful when migrating or mirroring a website or set of documents without using SSH.
* To index files on a server in order to review them or to check for malware.
* To copy a filtered set of files from one location on a server into to another location of the same server or a different server. Useful when stripping infected php files out of a website.
* Related to filtering files. To restore non executable files (e.g. image and text files) to a reinstalled (clean) website.
* To compare file changes. The indexer records file names, their directory location and size. When the downloader is first used it creates a log of files copied. When the downloader detects changes in the file names or directory names listed in the index file on server one the downloader logs the changes and prompts to download any new files and directories (continue the previous download). The new_files.log is a record of changes on server one since the last download was run.
* To see which files can be publicly downloaded from a server. This can help with assessing security risks. Can dot files or config files be downloaded? Check the download logs.

# Known Limitations and Bugs

* PHP files and other script files that servers restrict public access to are not downloaded by the downloader script. The script can (well, will) rereate them as empty files if they their extensions are not in the indexer scripts list of excluded file extensions.
* When the download script completes its run, instead of showing a success message it shows an "unexpected termination '<'" error message. Check the log files. The transfer probably completed successfully if the size of the downloaded_files.log on server two matches the size of the file_index log on server one and provided they both contain the same number of lines. Solution: Compare logs or use the indexer script on server two to create a file index to compare with the index on server one.

# Transfiler: Indexer

OK, let's amend the `index_files.php` script further, incorporating allowed directories, disallowed file extensions, and options to disable these settings. We'll also assess the script for improvements and apply them.


**Summary of Script Function:**

The `index_files.php` script is a PHP application that generates a CSV index of files and directories starting from a specified base path. The script allows the user to configure which files and directories are included in the index based on the following criteria:

*   **Allowed File Extensions:** Only files with extensions in the `$allowed_extensions` array are included (disabled by setting `$enable_allowed_extensions` to `false`).
*   **Disallowed File Extensions:** Files with extensions in the `$disallowed_extensions` array are excluded (disabled by setting `$enable_disallowed_extensions` to `false`).
*   **Allowed Directories:** Only files and directories within the specified `$allowed_directories` are included (disabled by setting `$enable_allowed_directories` to `false`). If the `$allowed_directories` array is empty and `$enable_allowed_directories` is set to `true`, all directories are allowed.
*   **Disallowed Directories:** Files and directories within the specified `$disallowed_directories` are excluded (disabled by setting `$enable_disallowed_directories` to `false`).

The script generates a CSV file with the following columns:

*   **Name:** The name of the file or directory.
*   **Relative Path:** The path to the file or directory relative to the base path.
*   **Type:** Indicates whether the item is a "file" or "directory".
*   **Size:** The size of the file in bytes (empty for directories).

**Usage Instructions:**

1.  **Set up a Web Server:** Make sure that you have a web server (e.g., Apache, Nginx) set up and configured with PHP support.
2.  **Copy the Script:** Copy the `index_files.php` script to a directory accessible by your web server.
3.  **Configure the Script (Optional):**
    *   Open the `index_files.php` script in a text editor.
    *   Modify the configuration settings at the top of the script to suit your needs:
        *   `$allowed_extensions`:  Add or remove file extensions that you want to allow in the index.
        *   `$disallowed_extensions`: Add or remove file extensions that you want to exclude from the index.
        *   `$allowed_directories`: Add directories (relative to the base path) that you want to allow in the index.  Leave the array empty to allow all directories.
        *   `$disallowed_directories`: Add directories (relative to the base path) that you want to exclude from the index.
        *   `$enable_allowed_extensions`: Set to `true` to enable the allowed extensions check, or `false` to disable it.
        *   `$enable_disallowed_extensions`: Set to `true` to enable the disallowed extensions check, or `false` to disable it.
        *   `$enable_allowed_directories`: Set to `true` to enable the allowed directories check, or `false` to disable it.
        *   `$enable_disallowed_directories`: Set to `true` to enable the disallowed directories check, or `false` to disable it.
    *   Save the changes to the script.
4.  **Access the Script:** Open a web browser and navigate to the URL of the `index_files.php` script.
5.  **Specify the Path:** Enter the path that you want to index in the "Path to index" field. This should be an absolute path on the server's file system.
6.  **Generate the Index:** Click the "Generate Index" button.
7.  **Download the CSV File:** If the script runs successfully, a link to the generated CSV file will be displayed. Click the link to download the file.

**Important Notes:**

*   **Security:** Always sanitize user input to prevent security vulnerabilities. This script relies on PHP to prevent unsafe activity on the server.
*   **Performance:** Indexing a large number of files can take a significant amount of time.
*   **Error Handling:** If any errors occur during the indexing process, they will be logged to the web server's error log.
