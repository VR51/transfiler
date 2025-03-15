#!/bin/bash

# Script to generate a CSV index of files and directories under the script's installation directory.

###
# Transfiler: Indexer v1.0.0
# BASH version
# Copyight 2025 VR51, Lee Hodson, Gemini Flash 2
#
# 1. Place this script on Server One.
# 2. Configure the settings that start at line 21.
# 3. Make the script executable: chmod 755 index_files.sh
# 4. Run the script: ./index_files.sh
# 5. Or as a one-liner to download, make executable and run the script: wget https://raw.githubusercontent.com/VR51/transfiler/refs/heads/main/index_files.sh && chmod 755 index_files.sh && ./index_files.sh
# 6. Answer a couple of questions
# 7. Copying files? Copy the generated index file's name (not the link) and use it with 'Transfiler: Downloader' either the web version or the BASH version.
# 8. Delete this script and its log files from your web server after use.
# 9. The script does not need to be named index_files.sh. You can give it a different file name for security purposes.
####

# Configuration Settings
ALLOWED_EXTENSIONS=(jpg jpeg png gif pdf txt doc docx xls xlsx csv)
DISALLOWED_EXTENSIONS=(php exe sh)
ALLOWED_DIRECTORIES=()  # Empty array allows all directories
DISALLOWED_DIRECTORIES=(".git" "node_modules" "cache")

ENABLE_ALLOWED_EXTENSIONS=0  # 0 for false, 1 for true
ENABLE_DISALLOWED_EXTENSIONS=1
ENABLE_ALLOWED_DIRECTORIES=0
ENABLE_DISALLOWED_DIRECTORIES=1

# Get the script's directory
SCRIPT_DIR="$(dirname "$(readlink -f "$0")")"
cd "$SCRIPT_DIR"

# Function to generate a unique filename
generate_unique_filename() {
  while true; do
    filename="index_$(date +%Y%m%d_%H%M%S)_$RANDOM.csv"
    if [[ ! -f "$filename" ]]; then
      echo "$filename"
      return
    fi
  done
}

# Generate a unique CSV filename
CSV_FILE=$(generate_unique_filename)

# Create the CSV header
echo "Name,Relative Path,Type,Size" > "$CSV_FILE"

# Function to check if a directory is allowed
is_directory_allowed() {
  local relative_path="$1"

  if [[ "$ENABLE_ALLOWED_DIRECTORIES" -eq 1 ]] && [[ ${#ALLOWED_DIRECTORIES[@]} -gt 0 ]]; then
    local is_allowed=0
    for allowed_dir in "${ALLOWED_DIRECTORIES[@]}"; do
      if [[ "$relative_path" == "./$allowed_dir"* ]]; then  #Check if it *starts* with the allowed directory
        is_allowed=1
        break
      fi
    done
    if [[ "$is_allowed" -eq 0 ]]; then
      return 1  # Not allowed
    fi
  fi
  return 0  # Allowed
}

# Function to check if a directory is disallowed
is_directory_disallowed() {
  local relative_path="$1"
  if [[ "$ENABLE_DISALLOWED_DIRECTORIES" -eq 1 ]]; then
    for disallowed_dir in "${DISALLOWED_DIRECTORIES[@]}"; do
      if [[ "$relative_path" == "./$disallowed_dir"* ]]; then
        return 0  # Disallowed
      fi
    done
  fi
  return 1 # Not disallowed
}

# Function to check if a file extension is allowed
is_extension_allowed() {
  local filename="$1"
  local extension="${filename##*.}"  # Get file extension

  if [[ "$ENABLE_ALLOWED_EXTENSIONS" -eq 1 ]]; then
    local is_allowed=0
    for allowed_ext in "${ALLOWED_EXTENSIONS[@]}"; do
      if [[ "$extension" == "$allowed_ext" ]]; then
        is_allowed=1
        break
      fi
    done
    if [[ "$is_allowed" -eq 0 ]]; then
      return 1  # Not allowed
    fi
  fi
  return 0 # Allowed
}

# Function to check if a file extension is disallowed
is_extension_disallowed() {
  local filename="$1"
  local extension="${filename##*.}"  # Get file extension

  if [[ "$ENABLE_DISALLOWED_EXTENSIONS" -eq 1 ]]; then
    for disallowed_ext in "${DISALLOWED_EXTENSIONS[@]}"; do
      if [[ "$extension" == "$disallowed_ext" ]]; then
        return 0  # Disallowed
      fi
    done
  fi
  return 1 # Allowed
}

# Use 'find' to walk the directory structure and generate the CSV data
find . -print0 | while IFS= read -r -d $'\0' item; do
  RELATIVE_PATH="./${item#./}" # Handle potential leading "./"
  NAME="$(basename "$item")"

  # Skip disallowed directories
  if [[ -d "$item" ]] && ! is_directory_disallowed "$RELATIVE_PATH"; then
    continue
  fi

  # Skip not allowed directories
  if [[ -d "$item" ]] && ! is_directory_allowed "$RELATIVE_PATH"; then
    continue
  fi

  # Check file extension restrictions
  if [[ -f "$item" ]]; then
    if ! is_extension_allowed "$NAME"; then
      continue
    fi

    if ! is_extension_disallowed "$NAME"; then
      continue
    fi
  fi

  if [ -d "$item" ]; then
    TYPE="directory"
    SIZE=""  # Size is not relevant for directories in this context
  elif [ -f "$item" ]; then
    TYPE="file"
    SIZE="$(stat -c %s "$item")"
  else
    TYPE="unknown"
    SIZE=""
  fi

  # Escape commas in file names to prevent CSV parsing issues
  NAME="${NAME//,/','}"

  echo "$NAME,$RELATIVE_PATH,$TYPE,$SIZE" >> "$CSV_FILE"
done

echo "CSV index file created: $CSV_FILE in $SCRIPT_DIR"
