# m_remote_media

This WordPress plugin loads uploads from a remote server (such as a production environment) on demand, so you do not necessarily have to load all the files of the uploads folder.

## Usage

Copy the m_remote_media.php file to your WordPress plugin directory (/wp-content/plugins).
After activation in the wp-admin please go to to `Settings › Remote Media` and enter the URL of the remote environment (maybe your production environment).
That’s all – maybe you have to flush the rewrite rules.

### Server-side

Avoid problems with server-side image processing by using the following action:

```
do_action( 'm_remote_media/load_attachment', '/path/to/your/local/file.jpg');

```

## Known issues

Not working on nginx webservers for now