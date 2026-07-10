using System.Security.Cryptography;
using System.Text.Json;
using System.Text.Json.Serialization;
using CUE4Parse.Encryption.Aes;
using CUE4Parse.FileProvider;
using CUE4Parse.MappingsProvider.Usmap;
using CUE4Parse.UE4.Assets.Exports.Texture;
using CUE4Parse.UE4.Objects.Core.Misc;
using CUE4Parse.UE4.Versions;
using CUE4Parse_Conversion.Textures;
using SkiaSharp;

// Image extractor. Reads an image manifest, opens the Satisfactory
// paks, decodes each texture and writes normalized PNGs at the requested sizes,
// plus an extraction-result.json with per-asset status + content hashes.
//
// Usage:
//   Extractor --manifest <image-manifest.json> --install-dir <game install> --out <dir>
//             [--engine GAME_UE5_6] [--engine-candidates GAME_UE5_6,GAME_UE5_5,...]
//             [--aes-key 0x...] [--sizes 256,64]

var opts = Args.Parse(args);
string manifestPath = opts.Require("manifest");
string installDir = opts.Require("install-dir");
string outDir = opts.Require("out");
int[] sizes = (opts.Get("sizes") ?? "256,64").Split(',', StringSplitOptions.RemoveEmptyEntries)
    .Select(s => int.Parse(s.Trim())).ToArray();

string paksDir = Path.Combine(installDir, "FactoryGame", "Content", "Paks");
string usmap = Path.Combine(installDir, "CommunityResources", "FactoryGame.usmap");

string engineList = opts.Get("engine") ?? opts.Get("engine-candidates")
    ?? "GAME_UE5_6,GAME_UE5_5,GAME_UE5_4,GAME_UE5_3";
// Tolerate unknown/not-yet-supported engine names (e.g. a future UE version read
// from the game's version file): skip them rather than crash, so remaining
// candidates still get tried.
EGame[] candidates = engineList.Split(',', StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries)
    .Select(s => Enum.TryParse<EGame>(s, out var g) ? (EGame?)g : LogSkip(s))
    .Where(g => g.HasValue).Select(g => g!.Value).ToArray();
if (candidates.Length == 0) throw new Exception($"No known engine version in '{engineList}'.");

static EGame? LogSkip(string name)
{
    Console.Error.WriteLine($"WARN: unknown engine '{name}' — skipping.");
    return null;
}

byte[] aesKey = ParseAesKey(opts.Get("aes-key"));

var manifest = JsonSerializer.Deserialize<List<ManifestEntry>>(
    File.ReadAllText(manifestPath), Json.Options) ?? throw new Exception("Empty/invalid manifest");
Console.WriteLine($"Manifest: {manifest.Count} assets | sizes: {string.Join(",", sizes)} | install: {installDir}");

foreach (var size in sizes)
    Directory.CreateDirectory(Path.Combine(outDir, size.ToString()));

// --- pick the engine version that actually parses this build ---
var provider = OpenProvider(paksDir, usmap, aesKey, candidates, manifest, out var engine);
Console.WriteLine($"Engine: {engine} | mounted files: {provider.Files.Count}");

var results = new List<AssetResult>(manifest.Count);
int ok = 0, failed = 0;
foreach (var entry in manifest)
{
    try
    {
        var texture = provider.LoadPackageObject<UTexture2D>(entry.AssetPath);
        using var bitmap = (texture.Decode() ?? throw new Exception("Decode() returned null")).ToSkBitmap();

        var outputs = new Dictionary<string, SizeResult>();
        foreach (var size in sizes)
        {
            string rel = Path.Combine(size.ToString(), entry.ObjectName + ".png");
            string abs = Path.Combine(outDir, rel);
            byte[] png = EncodePng(bitmap, size);
            File.WriteAllBytes(abs, png);
            outputs[size.ToString()] = new SizeResult(rel, Convert.ToHexString(SHA256.HashData(png)).ToLowerInvariant());
        }

        results.Add(new AssetResult(entry.AssetPath, entry.ObjectName, true, bitmap.Width, bitmap.Height, outputs, null));
        ok++;
    }
    catch (Exception e)
    {
        results.Add(new AssetResult(entry.AssetPath, entry.ObjectName, false, 0, 0, null,
            e.GetType().Name + ": " + e.Message.Split('\n')[0]));
        failed++;
        Console.Error.WriteLine($"  FAIL {entry.ObjectName}: {e.Message.Split('\n')[0]}");
    }
}

string resultPath = Path.Combine(outDir, "extraction-result.json");
File.WriteAllText(resultPath, JsonSerializer.Serialize(
    new ExtractionResult(engine.ToString(), sizes, manifest.Count, ok, failed, results), Json.Options));

Console.WriteLine($"Done: {ok} ok, {failed} failed → {resultPath}");
return failed > 0 && ok == 0 ? 1 : 0;

// ---- helpers ----

static IFileProvider OpenProvider(
    string paksDir, string usmap, byte[] aesKey, EGame[] candidates,
    List<ManifestEntry> manifest, out EGame chosen)
{
    // The shipped .usmap is optional for texture extraction: newer builds ship a
    // usmap format that this CUE4Parse build can't parse, but textures decode fine
    // without mappings and engine auto-detection stays unambiguous. So we try to
    // load it (helps other asset types) and silently fall back if it won't parse.
    FileUsmapTypeMappingsProvider? mappings = null;
    if (File.Exists(usmap))
    {
        try { mappings = new FileUsmapTypeMappingsProvider(usmap); }
        catch (Exception e)
        {
            Console.Error.WriteLine($"WARN: usmap unparseable ({e.GetType().Name}) — continuing without mappings.");
        }
    }

    var probe = manifest[0].AssetPath;
    Exception? last = null;
    foreach (var game in candidates)
    {
        try
        {
            // Case-insensitive path lookup: docs sometimes reference a texture with
            // different casing than the pak path (e.g. ".../Mam/UI" vs ".../MAM/UI").
            var provider = new DefaultFileProvider(
                paksDir, SearchOption.AllDirectories, new VersionContainer(game),
                StringComparer.OrdinalIgnoreCase);
            if (mappings != null) provider.MappingsContainer = mappings;
            provider.Initialize();
            provider.SubmitKey(new FGuid(), new FAesKey(aesKey));
            // Verify by fully decoding the first asset with this engine version.
            var tex = provider.LoadPackageObject<UTexture2D>(probe);
            _ = (tex.Decode() ?? throw new Exception("probe decode null")).ToSkBitmap();
            chosen = game;
            return provider;
        }
        catch (Exception e)
        {
            last = e;
        }
    }

    throw new Exception($"No candidate engine version parsed the paks. Last error: {last?.Message}");
}

static byte[] EncodePng(SKBitmap src, int size)
{
    using var dst = new SKBitmap(new SKImageInfo(size, size, SKColorType.Rgba8888, SKAlphaType.Premul));
    src.ScalePixels(dst, SKFilterQuality.High);
    using var img = SKImage.FromBitmap(dst);
    using var data = img.Encode(SKEncodedImageFormat.Png, 100);
    return data.ToArray();
}

static byte[] ParseAesKey(string? key)
{
    if (string.IsNullOrWhiteSpace(key)) return new byte[32];
    key = key.StartsWith("0x", StringComparison.OrdinalIgnoreCase) ? key[2..] : key;
    return Convert.FromHexString(key);
}

// ---- types ----

sealed record ManifestEntry(
    [property: JsonPropertyName("assetPath")] string AssetPath,
    [property: JsonPropertyName("objectName")] string ObjectName);

sealed record SizeResult(
    [property: JsonPropertyName("path")] string Path,
    [property: JsonPropertyName("sha256")] string Sha256);

sealed record AssetResult(
    [property: JsonPropertyName("assetPath")] string AssetPath,
    [property: JsonPropertyName("objectName")] string ObjectName,
    [property: JsonPropertyName("ok")] bool Ok,
    [property: JsonPropertyName("width")] int Width,
    [property: JsonPropertyName("height")] int Height,
    [property: JsonPropertyName("sizes")] Dictionary<string, SizeResult>? Sizes,
    [property: JsonPropertyName("error")] string? Error);

sealed record ExtractionResult(
    [property: JsonPropertyName("engine")] string Engine,
    [property: JsonPropertyName("requestedSizes")] int[] RequestedSizes,
    [property: JsonPropertyName("total")] int Total,
    [property: JsonPropertyName("ok")] int Ok,
    [property: JsonPropertyName("failed")] int Failed,
    [property: JsonPropertyName("assets")] List<AssetResult> Assets);

static class Json
{
    public static readonly JsonSerializerOptions Options = new()
    {
        WriteIndented = true,
        PropertyNameCaseInsensitive = true,
        DefaultIgnoreCondition = JsonIgnoreCondition.WhenWritingNull,
    };
}

sealed class Args
{
    private readonly Dictionary<string, string> _map;
    private Args(Dictionary<string, string> map) => _map = map;

    public static Args Parse(string[] args)
    {
        var map = new Dictionary<string, string>(StringComparer.OrdinalIgnoreCase);
        for (int i = 0; i < args.Length; i++)
        {
            if (!args[i].StartsWith("--")) continue;
            string key = args[i][2..];
            string value = i + 1 < args.Length && !args[i + 1].StartsWith("--") ? args[++i] : "true";
            map[key] = value;
        }
        return new Args(map);
    }

    public string? Get(string key) => _map.TryGetValue(key, out var v) ? v : null;
    public string Require(string key) => Get(key) ?? throw new ArgumentException($"Missing required --{key}");
}
