# TYPO3 Extension "tagpack2syscategories"

This extensions provides a command to migrate the tags and relations of the extension "tagpack" to the sys_category table.

## HowTo

Using this extension is really simple!

**Requirements**

* The tables of tagpack must be still available. The extension itself is not needed. 
* Install the extension.
* Access to the commandline

There are 2 commands available:

`./typo3/cli_dispatch.phpsh extbase migration:check` will give you some details if the migration will be possible

`./typo3/cli_dispatch.phpsh extbase migration:run` will run the migration and copy the tags and relations to the new structure.

**This extension does not**

* provide anything for the frontend
* migrate the table "*tx_tagpack_categories*"
* migrate the relation table "*tx_tagpack_tags_quodvide_mm*"

