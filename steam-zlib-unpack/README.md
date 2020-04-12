
# PHP: STEAM zlib `.z` file unpacker
This is a tool for unpacking the `.z` files you can pull from STEAM. It's useful for automatic deployment of mod updates for games based around the UE4 engine, like "ARK: Survival Evolved" or "Dark and Light".

Written in PHP to allow automation of web-based mod managers. Will happily run in CLI as well.

##### NOTE: The script will check for the UE4 "magic number" (0xC1 0x83 0x2A 0x9E) at file header; this behaviour may be changed by setting the `UE4Zlib::checkMagicNumber` property to `false`. You're likely going to have to modify the script to suit your express purpose *anyway* if you need to unpack generic zlib files.

The script reads the memory limit, and limits itself to 75% of that value (*minimum 320 KiB for data and state*) to perform its functions. The chunks STEAM uses are typically 128 KiB in size inflated, so it's going to operate in up to 256 KiB chunks (inflated + deflated worst-case scenario). If there's more than 1364 MiB of memory available, it'll limit itself to 1023 MiB regardless (this can be changed, see the `$maxMemory` property; **note: the real limit will be 75% of that**). Most files you're going to be unpacking will be *significantly smaller* than that --- with the exception of UE4 maps, which may be *gigabytes* in size (hence the limit).

*The rationale behind using a limit at all is to allow you parallel processing of compressed files --- including UE4 maps (which may be huge) --- without overwhelming the server, and at the same time without constantly reading/writing the files. This way, you're expected to use up at most `number of parallel processes * 1GB` of memory (note that due to how PHP's memory manager works, this may not always be the case --- stat your requirements beforehand, and process accordingly).*

## Usage
```
$object = new UE4Zlib("path/to/input/file");
$bytesWritten = $object->unpack("path/to/output/file");
```
##### NOTE: if the target file exists at uncompress time, it will be truncated to 0 bytes and subsequently overwritten. It is up to the programmer to implement checking if they want to overwrite the file (or perhaps indeed clear out the target directory entirely).

##### NOTE: the script doesn't check if there's enough free disk space in the remote location. You can always query the resultant file size by using `UE4Zlib::getInflatedSize()` and perform your own checking.

## Error checking
The script uses generic exceptions to report issues. In all cases, the issue is either programmer error, or otherwise unrecoverable (due to, for example, an unexpected value in the files). *There are no recoverable errors.*

The script will check the process at every stage, comparing all inflated/deflated sizes according to the header and declared index sizes. As such, you should never end up with a useless uncompressed file and not know about it.
