ace.define("ace/theme/genesworld",["require","exports","module","ace/lib/dom"], function(require, exports, module){
  exports.isDark = true;                 // set false for light themes
  exports.cssClass = "ace-genesworld";   // unique class
  exports.cssText = `
.ace-genesworld .ace_gutter {background:#1a1a1a;color:#bebebe}
.ace-genesworld .ace_gutter-active-line {background:#926969}
.ace-genesworld {background-color:#1a1a1a;color:#bebebe}
.ace-genesworld .ace_print-margin {width:1px;background:#1a1a1a}
.ace-genesworld .ace_cursor {color:#ffffff}
.ace-genesworld .ace_marker-layer .ace_selection {background:#72006a}
.ace-genesworld .ace_marker-layer .ace_active-line {background:#203644}
.ace-genesworld .ace_marker-layer .ace_step {background: rgb(102, 82, 0)}
.ace-genesworld .ace_marker-layer .ace_bracket {margin: -1px 0 0 -1px;  border: 1px solid #404040}
.ace-genesworld .ace_marker-layer .ace_selected-word {border: 1px solid #86b1dc}
.ace-genesworld .ace_multiselect .ace_selection.ace_start {box-shadow: 0 0 3px 0px #0f0f0f}
.ace-genesworld .ace_invisible {color:#404040}
.ace-genesworld .ace_meta {color: #ff6600}
.ace-genesworld .ace_keyword {color:#ff6600}
.ace-genesworld .ace_constant.ace_numeric {color:#4be14b}
.ace-genesworld .ace_constant.ace_other {color: #248686}
.ace-genesworld .ace_constant.ace_language {color:#0069ff}
.ace-genesworld .ace_string {color:#66ff00}
.ace-genesworld .ace_string.ace_regexp {color: #44b4cc}
.ace-genesworld .ace_comment {color:#9933cc;font-style:italic}
.ace-genesworld .ace_variable {color:#ffcc00}
.ace-genesworld .ace_variable.ace_parameter {font-style: italic}
.ace-genesworld .ace_support.ace_function {color:#ffcc00}
.ace-genesworld .ace_entity.ace_other.ace_attribute-name {font-style: italic;  color: #00ff00}
.ace-genesworld .ace_invalid {background:#000000;color:#ccff33}
.ace-genesworld .ace_fold {background:#ffcc00;border-color:#ffffff}
`;
  var dom = require("../lib/dom");
  dom.importCssString(exports.cssText, exports.cssClass);
});
