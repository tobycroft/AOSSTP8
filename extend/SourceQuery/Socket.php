<?php
/**
 * @author Pavel Djundik
 *
 * @link https://xpaw.me
 * @link https://github.com/xPaw/PHP-Source-Query
 *
 * @license GNU Lesser General Public License, version 2.1
 *
 * @internal
 */

namespace SourceQuery;

use SourceQuery\Exception\InvalidPacketException;
use SourceQuery\Exception\SocketException;

/**
 * Class Socket
 *
 * @package SourceQuery
 *
 * @uses SourceQuery\Exception\InvalidPacketException
 * @uses SourceQuery\Exception\SocketException
 */
class Socket extends BaseSocket
{
    public function Close(): void
    {
        if ($this->Socket !== null) {
            FClose($this->Socket);

            $this->Socket = null;
        }
    }

    public function Open(string $Address, int $Port, int $Timeout, int $Engine): void
    {
        $this->Timeout = $Timeout;
        $this->Engine = $Engine;
        $this->Port = $Port;
        $this->Address = $Address;

        $this->Socket = @FSockOpen('udp://' . $Address, $Port, $ErrNo, $ErrStr, $Timeout);

        if ($ErrNo || $this->Socket === false) {
            throw new SocketException('Could not create socket: ' . $ErrStr, SocketException::COULD_NOT_CREATE_SOCKET);
        }

        Stream_Set_Timeout($this->Socket, $Timeout);
        Stream_Set_Blocking($this->Socket, true);
    }

    public function Write(int $Header, string $String = ''): bool
    {
        $Command = Pack('ccccca*', 0xFF, 0xFF, 0xFF, 0xFF, $Header, $String);
        $Length = StrLen($Command);

        return $Length === FWrite($this->Socket, $Command, $Length);
    }

    /**
     * Reads from socket and returns Buffer.
     *
     * @return Buffer Buffer
     * @throws InvalidPacketException
     *
     */
    public function Read(int $Length = 1400): Buffer
    {
        $Buffer = new Buffer();
        $Buffer->Set(FRead($this->Socket, $Length));

        $this->ReadInternal($Buffer, $Length, [$this, 'Sherlock']);

        return $Buffer;
    }

    public function Sherlock(Buffer $Buffer, int $Length): bool
    {
        $Data = FRead($this->Socket, $Length);

        if (StrLen($Data) < 4) {
            return false;
        }

        $Buffer->Set($Data);

        return $Buffer->GetLong() === -2;
    }
}
