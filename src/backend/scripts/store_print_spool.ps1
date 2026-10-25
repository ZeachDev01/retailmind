param([Parameter(Mandatory=$true)][string]$PrinterName, [Parameter(Mandatory=$true)][string]$PayloadPath)
$ErrorActionPreference = 'Stop'
Add-Type @'
using System;
using System.Runtime.InteropServices;
public static class ReceiptSpool {
    [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)]
    public class DocInfo { public string pDocName="RetailMind saved receipt"; public string pOutputFile=null; public string pDataType="RAW"; }
    [DllImport("winspool.drv", CharSet=CharSet.Unicode, SetLastError=true)] public static extern bool OpenPrinter(string name, out IntPtr handle, IntPtr defaults);
    [DllImport("winspool.drv", CharSet=CharSet.Unicode, SetLastError=true)] public static extern int StartDocPrinter(IntPtr h, int level, [In] DocInfo info);
    [DllImport("winspool.drv", SetLastError=true)] public static extern bool WritePrinter(IntPtr h, byte[] bytes, int length, out int written);
    [DllImport("winspool.drv", SetLastError=true)] public static extern bool EndDocPrinter(IntPtr h);
    [DllImport("winspool.drv")] public static extern bool ClosePrinter(IntPtr h);
    public static void Print(string name, byte[] bytes) {
        IntPtr h;
        if(!OpenPrinter(name,out h,IntPtr.Zero)) throw new Exception("Configured printer unavailable");
        try {
            if(StartDocPrinter(h,1,new DocInfo())==0) throw new Exception("Cannot start print job");
            int written;
            bool ok=WritePrinter(h,bytes,bytes.Length,out written);
            bool ended=EndDocPrinter(h);
            if(!ok || written!=bytes.Length || !ended) throw new Exception("Delivery uncertain");
        } finally { ClosePrinter(h); }
    }
}
'@
[ReceiptSpool]::Print($PrinterName, [IO.File]::ReadAllBytes($PayloadPath))
Write-Output 'SPOOLED'
