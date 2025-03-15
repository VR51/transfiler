#!/bin/bash

# Script to download files listed in a CSV index from a remote server.

###
# Transfiler: Downloader v1.0.0
# BASH version
# Copyight 2025 VR51, Lee Hodson, Gemini Flash 2
#
# 1. Place this script on Server Two.
# 2. Configure the settings that start at line 21.
# 3. Make the script executable: chmod 755 download_files.sh
# 4. Run the script: ./download_files.sh
# 5. Or as a one-liner to download, make executable and run the script (Think: do you need to configure this?): wget && chmod 755 download_files.sh && ./download_files.sh
# 6. Answer a couple of questions
# 7. Tell the script the name of the `file index CSV` built by 'Transfiler: Downloader', either the web version or the BASH version.
# 8. Delete this script and its log files from your web server after use.
# 9. The script does not need to be named download_files.sh. You can give it a different file name for security purposes.
####

# Configuration Variables
ALLOWED_EXTENSIONS=(jpg jpeg png gif pdf txt doc docx xls xlsx csv)
EXCLUDED_EXTENSIONS=(php exe sh)
ENABLE_EXTENSION_CHECK=1 # 0 for false, 1 for true

# Default Log File Names
DOWNLOADED_LOG_FILE="downloaded_files.log"
FAILED_LOG_FILE="failed_files.log"
IGNORED_FILES_LOG_FILE="ignored_files.log"

# Function to sanitize filename based on allowed extensions
sanitize_filename() {
    filename="$1"
    allowed_extensions=("$@")
    shift # Remove filename
    shift # Remove first element in allowed_extensions array, which has already been used
    # Get the file extension
    extension="${filename##*.}"

    # Check if the extension is in the allowed list
    is_allowed="0"
    for ext in "${allowed_extensions[@]}"; do
        if [[ "$extension" == "$ext" ]]; then
            is_allowed="1"
            break
        fi
    done

    if [[ "$is_allowed" == "0" ]]; then
        echo ""  # Reject the file if the extension is not allowed
    else
      echo "$filename"
    fi
    # Sanitize the filename (remove special characters, spaces, etc.)
    # sanitized_filename=$(echo "$filename" | sed 's/[^a-zA-Z0-9._-]//g')
    # echo "$sanitized_filename"
}

# Function to download a file
download_file() {
  local remote_url="$1"
  local relative_path="$2"
  local target_file="$3"

  # Download the file using curl and check the return code
  curl -s -o "$target_file" "$remote_url/$relative_path"
  if [ $? -ne 0 ]; then
    echo "Failed to download file: $name"
    return 1  # Failure
  fi
  return 0  # Success
}

# Check if file is already downloaded by checking the logs.
is_file_downloaded(){
  log_file="$1"
  file_name="$2"
  relative_path="$3"

  if [[ -f "$log_file" ]]; then
    while IFS=, read -r name rel_path _ _; do
      if [[ "$name" == "$file_name" && "$rel_path" == "$relative_path" ]]; then
        return 0
      fi
    done < "$log_file"
  fi
  return 1
}

# Function to get number of total files before starting the download
get_number_of_files(){
  csv_file="$1"
  remote_url="$2"

  number_of_files=0

  # Fetch all lines using SSH
  content=$(curl -s "$remote_url/$csv_file")

  # Loop through the file, only count the files and exclude directory entries.
  while IFS=, read -r name relative_path type size; do
    if [[ "$type" == "file" ]] && [[ -n "$name" ]]; then
      number_of_files=$((number_of_files + 1))
    fi
  done <<< "$content"
  echo "$number_of_files"
}

# Function to remove characters that are incompatible on some OS's
escape_filename(){
  filename=$1
  filename="${filename// /_}"
  echo "$filename"
}

# Start Main Script
# ---
# Prompt for input
read -p "Enter the location of the index_files.sh script on the remote server: " REMOTE_SCRIPT_PATH
read -p "Enter the name of the CSV file: " CSV_FILE
read -p "Enter the remote URL: " REMOTE_URL

# Get number of total files to be downloaded before starting
number_of_files=$(get_number_of_files "$CSV_FILE" "$REMOTE_URL")

# If 0 files then exit.
if [ "$number_of_files" == "0" ]; then
  echo "No files found. Please check your settings."
  exit 0
fi

# Start by clearing out any old settings.
files_copied_count=0
directories_created_count=0
files_failed_count=0
# Display File Number on Web Page.
echo "Downloading $number_of_files files."

# Before moving on check for continue_download.

# Get number of total files to be downloaded before starting.
# Before moving on check for continue_download
is_downloaded=$(is_file_downloaded "$DOWNLOADED_LOG_FILE" "$CSV_FILE" "$REMOTE_URL")

# Make sure logs exist.
if [[ ! -f "$DOWNLOADED_LOG_FILE" ]]; then
  echo "Name,Relative Path,Type,Size" > "$DOWNLOADED_LOG_FILE"
fi

if [[ ! -f "$FAILED_LOG_FILE" ]]; then
  echo "Name,Relative Path,Type,Size" > "$FAILED_LOG_FILE"
fi

if [[ ! -f "$IGNORED_FILES_LOG_FILE" ]]; then
  echo "Name,Relative Path,Type,Size" > "$IGNORED_FILES_LOG_FILE"
fi

# Download the CSV file to local directory
content=$(curl -s "$REMOTE_URL/$CSV_FILE")

# Loop through the file to execute actions based on file type.
while IFS=, read -r name relative_path type size; do
  # Check if name is set to stop from looping and setting empty values.
  if [[ "$type" == "file" ]] && [[ -n "$name" ]]; then
    # Sanitize filenames and create download location for file.
    target_file=$(escape_filename "$relative_path")

    # Check the extensions
    if [[ "$ENABLE_EXTENSION_CHECK" -eq 1 ]]; then
      check=$(sanitize_filename "$name" "${ALLOWED_EXTENSIONS[@]}")

      # If file does not contain the extension, then skip file.
      if [[ -z "$check" ]]; then
        echo "Skipping disallowed file $relative_path with extension: $extension"
        continue
      fi
    fi

    if [[ ! -f "$relative_path" ]]; then
      # Start the download.
      downloaded=$(download_file "$REMOTE_URL" "$relative_path" "$target_file")
      if [[ "$downloaded" -eq 0 ]]; then
        # Add success count
        files_copied_count=$((files_copied_count + 1))
        echo "Downloaded $relative_path [$files_copied_count/$number_of_files]"

        # Start Adding logs to files.
        echo "$name,$relative_path,$type,$size" >> "$DOWNLOADED_LOG_FILE"
      else
        # Count the failed files.
        files_failed_count=$((files_failed_count + 1))
        echo "File $relative_path failed. Check the logs."

        # Save failed result in logs.
        echo "$name,$relative_path,$type,$size" >> "$FAILED_LOG_FILE"
      fi
    fi
  elif [[ "$type" == "directory" ]]; then
    # Check Directory to create if not already present.
    if [[ ! -d "$relative_path" ]]; then
      mkdir -p "$relative_path"
      directories_created_count=$((directories_created_count + 1))
      echo "Created Directory $relative_path"

      #Start Adding Logs to files.
      echo "$name,$relative_path,$type,$size" >> "$DOWNLOADED_LOG_FILE"
    fi
  fi
done <<< "$content"

# Status Feedback - Once execution is over.
echo "Downloaded: $files_copied_count, Failed: $files_failed_count directories Created: $directories_created_count "
echo "Finished."
