# Rename Uploads

Renames recently uploaded files to match format of "YY-MM-DD hh-mm-ss filename.ext".

## Usage

- This app automatically renames uploaded files with the tag "needs_rename" based on their exif metadata. If there is no exif data, then it uses the modification timestamp to rename the file.
- You must set up and configure automated tagging flow in nextcloud for this app to recognize what files to rename.
