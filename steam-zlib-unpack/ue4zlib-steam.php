<?php
class UE4Zlib {
   private $inputHandle = null;
   private $metadata = [];
   private $memoryLimit = 0;

   public function __construct($filePathIN){
      if ( realpath($filePathIN) === null )
         throw new Exception("input file path doesn't exist (realpath: null)");

      $handle = @fopen($filePathIN, "r"); // I'm silencing the error here so that it doesn't produce ugly outputs
      if ( $handle === false )
         throw new Exception("failed opening the input file");

      $result = flock($handle, LOCK_SH); // soft-lock for reading
      if ( $result === false )
         throw new Exception("failed locking the input file for reading - is some other process accessing it?");

      $this->inputHandle = $handle;
      $this->parseHeaderData();

      $memory_limit = ini_get('memory_limit');
      if ( $memory_limit == "-1" ){
         $memory_limit = "1364M"; // limit ourselves to 1 GB of RAM (75% of this value)
      }
      if (preg_match('/^(\d+)(.)$/', $memory_limit, $matches)) {
          if ($matches[2] == 'G')
              $memory_limit = $matches[1] * 1024 * 1024 * 1024; // nnnG -> nnnGB
          else if ($matches[2] == 'M')
              $memory_limit = $matches[1] * 1024 * 1024; // nnnM -> nnn MB
          else if ($matches[2] == 'K')
              $memory_limit = $matches[1] * 1024; // nnnK -> nnn KB
      }

      // I set the minimum memory limit at a "reasonable" value of 320 KiB, because each chunk (128 KiB inflated) will at worst
      // be 128 KiB deflated, so a total of 256 KiB + a healthy amount (+50%) for the garbage collector; note that if PHP has
      // less than about 10~20 MiB of memory, I doubt it'll be able to do much, or even start up at all?
      if ( $memory_limit < 327680 )
         throw new Exception("detected PHP memory limit is less than 320 KiB - the unpack process won't be able to proceed");

      $memory_limit = floor( bcmul($memory_limit, 0.75) );
      echo "Resultant memory limit: {$memory_limit}\n";

      $this->memoryLimit = (int) $memory_limit;
   }

   public function __destruct(){
      $result = flock($this->inputHandle, LOCK_UN);
      if ( $result === false )
         throw new Exception("failed unlocking the input file (unexpected!)");

      $result = @fclose($this->inputHandle);
      if ( $result === false )
         throw new Exception("failed closing the input file (unexpected!)");
   }

   private function parseHeaderData(){
      $fileStat = array_slice(fstat($this->inputHandle), 13);

      $readBuffer = fread($this->inputHandle, 32);
      $meta = unpack("Pmagic/Pblock-size/Ppayload-size/Puncompressed-size", $readBuffer);
      $meta['header-length'] = $fileStat['size'] - $meta['payload-size'];
      $meta['index-length']  = $fileStat['size'] - $meta['payload-size'] - 32;
      $meta['block-count']   = $meta['index-length'] / 16;

      // make sure block-count is an int, otherwise we're missing something!
      if ( $meta['block-count'] != (int) $meta['block-count'] )
         throw new Exception("Block count is not an integer ({$meta['block-count']}), expected an integer");

      // construct the index, and verify all entries for validity
      $meta['index'] = [];
      $readBuffer = fread($this->inputHandle, $fileStat['size'] - $meta['payload-size'] - 32);
      $sumBlocksRegular = 0;
      $inflatedSum = 0;
      $deflatedSum = 0;
      for($i = 0; $i < $meta['block-count']; $i++){
         $indexEntry = unpack("Ppayload/Puncompressed", substr($readBuffer, $i * 16, 16));

         if ( $i < $meta['block-count'] - 1 ){
            $sumBlocksRegular += $indexEntry['uncompressed'];
         }

         $inflatedSum += $indexEntry['uncompressed'];
         $deflatedSum += $indexEntry['payload'];
         $meta['index'][] = $indexEntry;
      }

      // abort if expected inflated size of the blocks doesn't match the header
      if ( $inflatedSum != $meta['uncompressed-size'] )
         throw new Exception("expected uncompressed payload meta field ({$meta['uncompressed-size']}) doesn't match actual expected sum of inflated blocks ({$inflatedSum})");

      // abort if recorded deflated size of the blocks doesn't match the header
      if ( $deflatedSum != $meta['payload-size'] )
         throw new Exception("compressed payload meta field ({$meta['payload-size']}) doesn't match actual sum of deflated blocks ({$deflatedSum})");

      // abort if the regular blocks (all but the last) aren't sized according to the header's "block-size"
      if ( ($sumBlocksRegular / $meta['block-size']) != sizeof($meta['index']) -1 )
         throw new Exception("chunk size doesn't match throughout the index");

      $this->metadata = $meta;
   }

   public function unpack($filePathOUT){
      if ( realpath($filePathOUT) === null ){
         $handle = @fopen($filePathOUT, "w"); // create the file
         if ( $handle === false )
            throw new Exception("failed opening the output file; file didn't exist - permission error?");

         $result = flock($handle, LOCK_EX); // demand an exclusive lock - wait for it (NB = false)
         if ( $result === false )
            throw new Exception("failed locking the output file");
      } else {
         // file already exists
         $handle = @fopen($filePathOUT, "c"); // don't truncate - we'll wait for a lock
         if ( $handle === false )
            throw new Exception("failed opening the output file; file exists - permission error? file in use?");

         $result = flock($handle, LOCK_EX); // demand an exclusive lock - wait for it (NB = false)
         if ( $result === false )
            throw new Exception("failed locking the existing output file - is the file in use by some other process?");
      }

      // unpack the file...
      $result = ftruncate($handle, 0);
      if ( $result === false )
         throw new Exception("failed truncating the output file (unexpected!)");

      $bytesWritten = 0;
      $index = $this->metadata['index'];
      while ( sizeof($index) ){
         $inflateIndex = ['size' => 0, 'chunks' => []];
         foreach($index as $indexID => $indexData){
            $proposedNewMemoryUse = $inflateIndex['size'] + $indexData['payload'] + $indexData['uncompressed'];
            if ( $proposedNewMemoryUse > $this->memoryLimit )
               break;
            $inflateIndex['size'] = $inflateIndex['size'] + $indexData['payload'] + $indexData['uncompressed'];
            $inflateIndex['chunks'][] = ['id' => $indexID, 'data' => $indexData];
            unset($index[$indexID]);
         }

         $writeBuffer = "";
         foreach($inflateIndex['chunks'] as $chunkData){
            $chunkID = $chunkData['id'];
            $chunkInfo = $chunkData['data'];
            $payloadChunk = fread($this->inputHandle, $chunkInfo['payload']);
            if ( strlen($payloadChunk) != $chunkInfo['payload'] )
               throw new Exception("read payload chunk less than expected payload length at block ID {$blockID}");

            $uncompressed = gzuncompress($payloadChunk);
            if ( strlen($uncompressed) != $chunkInfo['uncompressed'] )
               throw new Exception("inflated block size (".strlen($uncompressed).") doesn't match expected inflated size ({$chunkInfo['uncompressed']}) at block number {$chunkID}");

            $writeBuffer .= $uncompressed;
            unset($uncompressed);
            unset($payloadChunk);
         }

         $result = fwrite($handle, $writeBuffer, strlen($writeBuffer));
         if ( $result === false )
            throw new Exception("failed to write to output handle");

         if ( $result != strlen($writeBuffer) )
            throw new Exception("failed to write ".strlen($writeBuffer)." bytes of data ({$result} written)");

         $bytesWritten += $result;
      }
      // ...done unpacking the file
      if ( $bytesWritten != $this->metadata['uncompressed-size'] )
         throw new ZlibException("inflated file size ({$bytesWritten}) doesn't match expected inflated size ({$this->metadata['uncompressed-size']})");

      $outputFileStat = array_slice(fstat($handle), 13);
      if ( $outputFileStat['size'] != $this->metadata['uncompressed-size'] )
         throw new ZlibException("resultant file size ({$outputFileStat['size']}) doesn't match expected inflated size ({$this->metadata['uncompressed-size']})");

      $result = flock($handle, LOCK_UN);
      if ( $result === false )
         throw new Exception("failed unlocking the output file (unexpected!)");

      $result = @fclose($handle);
      if ( $result === false )
         throw new Exception("failed closing the output file (unexpected!)");

      return $bytesWritten;
   }
}
