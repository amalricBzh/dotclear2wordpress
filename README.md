# dotclear2wordpress
Dotclear to Wordpress convertor

This is my "quick and dirty" Dotpress flatfile to Wordpress convertor.
I need it to convert my old Gandi blog (Dotclear) to Wordpress import format,
and I didn't found anything other free tool for this task. 

Why the code is so hugly ?
----
Because I will use only once, I do not want to spend long hours for a one-shot project.
But if you are interested by this project, feel free to use and fork it.

How can I use it ?
-----
It's very simple. First of all, `git clone` this project.
1. Export your Dotclear blog as a flatFile (.txt). Save it where you want.
2. Export your media (images) and unzip it (in a `tmp/import_dc` directory for example).
3. Edit the file and change the `$settings` array values at the beggining of the file.
4. Then :

    php convert.php --input=<path/dotclear_flat_file.txt> --output=<path/wordpress_import_file.xml>

You may omit the output parameter, default value is `wordpress.xml`.

5. Then import `wordpress.xml` with WP importer, and move the new content files in
`wp-content/uploads` (the `image_base_path` parameter of step 3). And enjoy!

Is it fully fonctionnal ?
-----
Yes and not : it's ok for me, it can import my pages, posts, categories, tags and
comments, and my media files. I migrated two blogs, one with 700 articles.

But it miss some functionnality I do not need. Feel 
free to improve the script and submit your pull requests !
