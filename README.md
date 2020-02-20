# dotclear2wordpress
Dotclear to Wordpress convertor

This is my "quick and dirty" Dotpress flatfile export to Wordpress convertor.
I need it to convert my old Gandi blog (Dotclear) to Wordpress import format,
and I didn't found anything other free tool for this task. 

Why the code is so hugly ?
----
Because I will use only once, I do not want to spend long hours for a one-shot project.
But if you are interested by this project, feel free to use and fork it.

How can I use it ?
-----
It's very simple. First edit the file and change the `$settings` array values at the
beggining of the file. Then :

    php convert.php --input=<path/dotclear_flat_file.txt> --output=<path/wordpress_import_file.xml>

You may omit the output parameter, default value is `wordpress.xml

Is it fully fonctionnal ?
-----
Yes and not : it's ok for me, it can import my pages, posts, categories, tags and
comments.

But it still do not handle images and media library : this is my next and last TODO
on this project.
