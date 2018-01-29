# Lucimoo EPUB import/export plugins for the Moodle book module

Lucimoo consists of two plugins for the Moodle book module:

*   The "importepub" plugin provides functionality to import
    content from EPUB ebooks into book module books.

*   The "exportepub" plugin provides functionality to export
    book module books as EPUB ebooks.


## Requirements

The book module is included in Moodle 2.3 and later, and by
default these plugins can only be installed in these versions
of Moodle. If you use Moodle 2.0-2.2 and have manually installed
the book module and want to use these plugins, you must remove
the line "$plugin->requires = 2012062500;" from the files
"importepub/version.php" and "exportepub/version.php" before
they can be installed.


## Installation

The import and export plugins can be installed independently
of each other, so if you only want one of them you do not
need to install the other.

In Moodle 2.5 and later you can install plugins from the
"Site administration" view. In older versions of Moodle
you need to install them manually.

Note that the "Administration" block (or "Settings" block
in some versions of Moodle) must be visible in the
user interface for the plugins to work properly. It is
visible in default configurations, but can be configured
to not be displayed, in which case users will not see the
user interface controls for the plugins.

General plugin installation instructions are available at
http://docs.moodle.org/31/en/Installing_plugins

### Installation from the "Site administration" view in Moodle 3.0 and later

1.  Login as admin and visit the Moodle
    "Site administration" view, and click on
    "Site administration" > "Plugins" > "Install plugins"
    on the left.

2.  Select the Lucimoo ZIP package you want to install.

3.  Click on the "Install plugin from the ZIP file" button.

4.  Click on the "Continue" button.

5.  Repeat 1-4 with the other Lucimoo ZIP package if you
    want to install both the import and the export plugins.

### Installation from the "Site administration" view in Moodle 2.5-2.8

1.  Login as admin and visit the Moodle
    "Site administration" view, and click on
    "Site administration" > "Plugins" > "Install add-ons"
    on the left.

2.  Choose "Book / Book tool (booktool)" as the Plugin type.

3.  Select the Lucimoo ZIP package you want to install.

4.  Check the "Acknowledgement" box.

5.  Click on the "Install add-on from the ZIP file" button.

6.  Click on the "Install add-on!" button.

7.  Repeat 1-6 with the other Lucimoo ZIP package if you
    want to install both the import and the export add-ons.

### Manual installation

This is possible with Moodle 2.0 and later.

1.  Unzip the Lucimoo ZIP package(s) to get the folder(s)
    "importepub" and/or "exportepub".

2.  Upload or copy the "importepub" and/or "exportepub"
    folder(s) into the "mod/book/tool/" folder of your
    Moodle installation.

3.  Login as admin and visit the Moodle
    "Site administration" view, and click on
    "Site administration" > "Notifications" on the left
    and follow the instructions to finish the installation.

### Upgrading from an older version of Lucimoo to a newer version

The Lucimoo plugins do not store any plugin specific data in the
Moodle database. This means that you do not loose any data if you
uninstall them, and you can upgrade to another version of the
Lucimoo plugins simply by installing the new version.


## Configuration

The export plugin has a few settings that can be changed by
editing the file "config.php".


## Usage

### Exporting a book as an EPUB ebook

1.  Display the book you want to export.

2.  Click on the "Download as ebook" link under
    "Administration" > "Book administration" on the left.
    (In some versions of Moodle it is instead
    located under "Settings" > "Book administration")

### Importing chapters from an EPUB ebook into an existing book

1.  Display the book you want to import chapters into.

2.  Click on the "Turn editing on" link under
    "Administration" > "Book administration" on the left.
    (In some versions of Moodle it is instead
    located under "Settings" > "Book administration")

3.  Click on the "Import chapters from ebook" link under
    "Administration" > "Book administration" on the left.

4.  Select the EPUB file and click on "Import".

### Create new books from EPUB ebooks

This functionality is only available with Moodle 2.5 and later.

1.  Click on the "Turn editing on" link under
    "Administration" > "Book administration" on the left.
    (In some versions of Moodle it is instead
    located under "Settings" > "Book administration")

2.  Create a new (temporary) book in the section where
    you want to import the new book(s). To do this you
    click on the "Add an activity or resource" link and
    select "Book", and then fill in a title etc.

3.  Display the temporary book. (As the book is empty,
    an editing form will be displayed.)

4.  Click on the "Import ebook as new book" link under
    "Administration" > "Book administration" on the left.

5.  Either:

    a) Select the EPUB file(s) you want to import,
       and click on "Import".

    or:

    b) Enter URL:s for the EPUB file(s) you want to import,
       one on each line in the textbox, and click on
       "Import from URL:s".

6.  Delete the temporary book you created in step 1.

    (Steps 2 and 6 are only necessary if the section
    does not already contain any books. You must display
    a book to see the "Import ebook as new book" link,
    but it can be any book. Unfortunately Moodle does
    not provide any better place to put such a link.)

### Import options

- Create one book per chapter:
  This will create one book for every chapter, instead of only
  one book with many chapters.

- Add header:
  This allows you to type in a string of HTML code that is added
  at the start of every chapter.

- Add footer:
  This allows you to type in a string of HTML code that is added
  at the end of every chapter.

- Subchapters:
  This allows you to divide chapters into subchapters.
  You can specify which HTML tag to start a new subchapter at,
  and optionally one or more class names that must match.

- Enable stylesheets:
  This enables stylesheets.

- Prevent small text:
  This will try to prevent the text size from being smaller
  than the default text size.

- Ignore font family:
  This will try to make all text appear in the default font instead
  of any font specified in the EPUB file.


## Thanks

Some features of the plugin were developed with support from
the Ministry Division of the Archbishops’ Council of the Church of England.


## Credits

French translation by Sophie Canal.

Norwegian translation by Haakon Meland Eriksen.

Spanish translation by Lupa.

The Lucimoo EPUB import plugin includes code from the
following external project:

PHP-CSS-Parser
Copyright (c) 2011 Raphael Schweikert, http://sabberworm.com/
https://github.com/sabberworm/PHP-CSS-Parser

The Lucimoo EPUB export plugin includes code from the
following external project:

PHPZip
Copyright 2009-2012 A. Grandt
https://github.com/Grandt/PHPZip


## Contact information

Web site: http://lucidor.org/lucimoo/
