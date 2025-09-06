ace.define("ace/theme/genesworld",["require","exports","module","ace/lib/dom"], function(require, exports, module){
  exports.isDark = true;                 // set false for light themes
  exports.cssClass = "ace-genesworld";   // unique class
  exports.cssText = `
.ace-genesworld .ace_gutter {background:#1b1e24;color:#8a93a5}
.ace-genesworld {background-color:#1b1e24;color:#e6e6e6}
.ace-genesworld .ace_print-margin {width:1px;background:#2a2f36}
.ace-genesworld .ace_cursor {color:#f0f0f0}
.ace-genesworld .ace_marker-layer .ace_selection {background:#33415e}
.ace-genesworld .ace_marker-layer .ace_active-line {background:#222733}
.ace-genesworld .ace_gutter-active-line {background:#222733}
.ace-genesworld .ace_invisible {color:#3a4352}
.ace-genesworld .ace_keyword {color:#c792ea}
.ace-genesworld .ace_constant.ace_numeric {color:#f78c6c}
.ace-genesworld .ace_constant.ace_language {color:#ffcb6b}
.ace-genesworld .ace_string {color:#c3e88d}
.ace-genesworld .ace_comment {color:#6b778d;font-style:italic}
.ace-genesworld .ace_variable {color:#82aaff}
.ace-genesworld .ace_support.ace_function {color:#80cbc4}
.ace-genesworld .ace_invalid {background:#ff2d55;color:#1b1e24}
.ace-genesworld .ace_fold {background:#80cbc4;border-color:#e6e6e6}
`;
  var dom = require("../lib/dom");
  dom.importCssString(exports.cssText, exports.cssClass);
});
